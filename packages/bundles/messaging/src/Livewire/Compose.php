<?php

namespace Kintai\Bundles\Messaging\Livewire;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Kintai\Bundles\Messaging\MessagingAccess;
use Kintai\Bundles\Messaging\MessagingActions;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * « Nouveau message » : un magasin, un ou plusieurs collègues de ce magasin, un objet et un premier message.
 */
#[Title('New message')]
class Compose extends Component
{
    use ResolvesViews;

    public string $storeId = '';

    /** @var list<string> */
    public array $recipientIds = [];

    public string $subject = '';

    public string $body = '';

    public function mount(): void
    {
        abort_unless($this->access()->can($this->user(), 'messaging.create'), 403);

        $stores = $this->access()->composeStores($this->user());
        abort_if($stores === [], 404);

        $this->storeId = (string) array_key_first($stores);
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

    public function updatedStoreId(): void
    {
        $this->reset('recipientIds');
    }

    public function send(MessagingActions $actions): void
    {
        $this->validate([
            'storeId' => ['required', 'integer'],
            'recipientIds' => ['required', 'array', 'min:1'],
            'recipientIds.*' => ['integer'],
            'subject' => ['required', 'string', 'max:'.MessagingActions::SUBJECT_MAX],
            'body' => ['required', 'string', 'max:'.MessagingActions::BODY_MAX],
        ]);

        try {
            $thread = $actions->start($this->user(), (int) $this->storeId, $this->subject, $this->body, array_map('intval', $this->recipientIds));
        } catch (ValidationException $e) {
            $this->toastFailure($e);

            return;
        }

        $this->redirectRoute('messaging.thread', ['id' => $thread->id], navigate: true);
    }

    public function render(): View
    {
        $user = $this->user();
        $stores = $this->access()->composeStores($user);
        $storeId = ctype_digit($this->storeId) && isset($stores[(int) $this->storeId]) ? (int) $this->storeId : 0;

        return app('view')->make($this->viewName('compose'), [
            'stores' => $stores,
            'recipients' => $storeId > 0 ? $this->access()->recipients($user, $storeId) : [],
        ]);
    }
}
