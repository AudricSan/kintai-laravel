<?php

namespace Kintai\Bundles\Messaging\Livewire;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Kintai\Bundles\Messaging\MessagingAccess;
use Livewire\Component;

/**
 * Widget « Messages non lus » des tableaux de bord : le nombre de conversations non lues et un lien vers la messagerie.
 */
class UnreadWidget extends Component
{
    use ResolvesViews;

    public function render(): View
    {
        /** @var User $user */
        $user = Auth::user();

        return app('view')->make($this->viewName('widget'), ['unread' => app(MessagingAccess::class)->unreadCount($user)]);
    }
}
