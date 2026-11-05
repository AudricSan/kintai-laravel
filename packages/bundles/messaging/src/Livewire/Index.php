<?php

namespace Kintai\Bundles\Messaging\Livewire;

use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Kintai\Bundles\Messaging\MessagingAccess;
use Kintai\Bundles\Messaging\MessagingActions;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * « Messages » : les conversations de l'utilisateur (reçues, envoyées, non lues). Seules celles dont il est participant
 * s'affichent ; en retirer une ne la retire que de sa liste.
 */
#[Title('Messages')]
class Index extends Component
{
    use ResolvesViews;

    public const TABS = ['inbox', 'sent', 'unread'];

    #[Url(except: 'inbox')]
    public string $tab = 'inbox';

    #[Locked]
    public int $hidingId = 0;

    public function mount(): void
    {
        abort_unless($this->access()->canUse($this->user()), 404);

        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'inbox';
        }
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

    public function confirmHiding(int $id): void
    {
        $thread = $this->access()->thread($this->user(), $id);
        abort_if($thread === null, 404);

        $this->hidingId = $thread->id;
        Flux::modal('confirm-thread-hiding')->show();
    }

    public function hide(MessagingActions $actions): void
    {
        $thread = $this->access()->thread($this->user(), $this->hidingId);
        abort_if($thread === null, 404);

        $actions->hide($this->user(), $thread);

        $this->reset('hidingId');
        Flux::modal('confirm-thread-hiding')->close();
        Flux::toast(variant: 'success', text: __('Conversation removed from your list.'));
    }

    public function render(): View
    {
        $user = $this->user();
        $tab = in_array($this->tab, self::TABS, true) ? $this->tab : 'inbox';

        $threads = $this->access()->listing($user, $tab)
            ->with(['store:id,name', 'participants.user:id,name', 'latestMessage.sender:id,name'])
            ->withCount('messages')
            ->limit(200)
            ->get();

        return app('view')->make($this->viewName('index'), [
            'threads' => $threads,
            'tab' => $tab,
            'userId' => $user->id,
            'canCompose' => $this->access()->composeStores($user) !== [],
        ]);
    }
}
