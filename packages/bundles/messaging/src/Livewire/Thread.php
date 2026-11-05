<?php

namespace Kintai\Bundles\Messaging\Livewire;

use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Kintai\Bundles\Messaging\MessagingAccess;
use Kintai\Bundles\Messaging\MessagingActions;
use Kintai\Bundles\Messaging\Models\MessageThread;
use Kintai\Bundles\Messaging\Models\ThreadMessage;
use Kintai\Contracts\Notifications\Notifier;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Une conversation : ses messages (rafraîchis toutes les quelques secondes), la réponse, la suppression de ses propres
 * messages et le retrait de la conversation de sa liste. Réservée à ses participants : tout autre utilisateur obtient 404.
 */
#[Title('Conversation')]
class Thread extends Component
{
    use ResolvesViews;

    #[Locked]
    public int $threadId = 0;

    #[Locked]
    public int $deletingId = 0;

    public string $body = '';

    public function mount(int $id): void
    {
        $this->threadId = $this->thread($id)->id;
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    private function access(): MessagingAccess
    {
        return app(MessagingAccess::class);
    }

    private function thread(int $id): MessageThread
    {
        $thread = $this->access()->thread($this->user(), $id);
        abort_if($thread === null, 404);

        return $thread;
    }

    public function reply(MessagingActions $actions): void
    {
        $this->validate(['body' => ['required', 'string', 'max:'.MessagingActions::BODY_MAX]]);

        try {
            $actions->reply($this->user(), $this->thread($this->threadId), $this->body);
        } catch (ValidationException $e) {
            $this->toastFailure($e);

            return;
        }

        $this->reset('body');
    }

    public function confirmMessageDeletion(int $messageId): void
    {
        $message = ThreadMessage::query()->where('thread_id', $this->threadId)->find($messageId);
        abort_if($message === null || $message->sender_id !== $this->user()->id, 404);

        $this->deletingId = $message->id;
        Flux::modal('confirm-message-deletion')->show();
    }

    public function deleteMessage(MessagingActions $actions): void
    {
        $this->thread($this->threadId);
        $message = ThreadMessage::query()->where('thread_id', $this->threadId)->find($this->deletingId);
        abort_if($message === null, 404);

        $actions->deleteMessage($this->user(), $message);

        $this->reset('deletingId');
        Flux::modal('confirm-message-deletion')->close();
        Flux::toast(variant: 'success', text: __('Message deleted.'));
    }

    public function confirmHiding(): void
    {
        Flux::modal('confirm-thread-hiding')->show();
    }

    public function hide(MessagingActions $actions): void
    {
        $actions->hide($this->user(), $this->thread($this->threadId));

        $this->redirectRoute('messaging.index', navigate: true);
    }

    public function render(): View
    {
        $user = $this->user();
        $thread = $this->thread($this->threadId);

        // Ouverte (ou rafraîchie), la conversation est lue : son compteur et ses notifications aussi.
        app(MessagingActions::class)->markRead($user, $thread);
        app(Notifier::class)->markRead($user, 'message_thread', $thread->id);

        return app('view')->make($this->viewName('thread'), [
            'thread' => $thread->load('store:id,name'),
            'messages' => $thread->messages()->with('sender:id,name')->orderBy('sent_at')->orderBy('id')->get(),
            'participants' => $thread->participants()->with('user:id,name')->get(),
            'userId' => $user->id,
            'canReply' => $this->access()->canReply($user, $thread),
        ]);
    }
}
