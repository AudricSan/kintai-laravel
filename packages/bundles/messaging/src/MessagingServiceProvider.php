<?php

namespace Kintai\Bundles\Messaging;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Kintai\Bundles\Messaging\Livewire\Compose;
use Kintai\Bundles\Messaging\Livewire\Index;
use Kintai\Bundles\Messaging\Livewire\Thread;
use Kintai\Bundles\Messaging\Livewire\UnreadWidget;
use Kintai\Contracts\Bundle\BundleServiceProvider;
use Kintai\Contracts\Dashboard\DashboardWidget;
use Kintai\Contracts\Dashboard\WidgetSize;
use Kintai\Contracts\Events\UserAnonymized;
use Kintai\Contracts\Navigation\NavigationItem;
use Kintai\Contracts\Navigation\NavigationRegistry;
use Kintai\Contracts\Notifications\NotificationType;
use Livewire\Livewire;

/**
 * Messagerie interne : conversations entre collègues d'un magasin, lues par leurs seuls participants, avec compteur de
 * non-lus. Droits `messaging.view/send/create` (voir `MessagingAccess`), fonctionnalité de magasin `messages`.
 */
class MessagingServiceProvider extends BundleServiceProvider
{
    public const SLUG = 'messaging';

    public const FEATURE = 'messages';

    public const NOTIFICATION = 'message_received';

    public function slug(): string
    {
        return self::SLUG;
    }

    public function name(): string
    {
        return 'Messaging';
    }

    public function version(): string
    {
        return '1.1.0';
    }

    public function description(): string
    {
        return 'Internal messaging between the members of a store: private conversations, unread counter, notifications.';
    }

    public function requiredContract(): string
    {
        return '^1.4';
    }

    protected function bootBundle(): void
    {
        if ($this->isEnabled()) {
            Livewire::component('messaging.index', Index::class);
            Livewire::component('messaging.compose', Compose::class);
            Livewire::component('messaging.thread', Thread::class);
            Livewire::component('messaging.unread-widget', UnreadWidget::class);
        }

        // Tous les rôles du Core gardent la messagerie telle qu'elle était : lire, répondre et ouvrir une conversation.
        $this->permissions('messaging', ['view', 'send', 'create'], [
            'manager' => ['view', 'send', 'create'],
            'employee' => ['view', 'send', 'create'],
        ]);
        $this->storeFeature(self::FEATURE, 'Messaging');

        $usable = fn (Authenticatable $user): bool => $user instanceof User && $this->app->make(MessagingAccess::class)->canUse($user);

        $this->navigation(new NavigationItem(
            key: 'messaging', label: 'Messages', route: 'messaging.index', icon: 'chat-bubble-left-right',
            section: NavigationRegistry::SECTION_REQUESTS, order: 40, permission: 'messaging.view', visible: $usable, current: ['messaging.*'],
            badge: fn (Authenticatable $user): int => $user instanceof User ? $this->app->make(MessagingAccess::class)->unreadCount($user) : 0,
        ));
        foreach ([DashboardWidget::EMPLOYEE, DashboardWidget::MANAGER] as $audience) {
            $this->widgets(new DashboardWidget(
                key: 'messaging.'.$audience, label: 'Unread messages', audience: $audience, component: 'messaging.unread-widget',
                permission: 'messaging.view', size: WidgetSize::Small, order: 45, when: $usable,
            ));
        }
        $this->notificationTypes(new NotificationType(self::NOTIFICATION, 'New message', 'messages', 'chat-bubble-left-right', push: true, referenceType: 'message_thread'));
        foreach ([
            'message.sent' => 'Conversation started',
            'message.replied' => 'Reply sent',
            'message.deleted' => 'Message deleted',
            'message.thread_hidden' => 'Conversation removed from a list',
            'message.thread_deleted' => 'Conversation deleted',
            'message.participant_added' => 'Participant added to a conversation',
        ] as $key => $label) {
            $this->activityAction($key, $label, 'Messaging');
        }

        // Le contenu des messages est une donnée personnelle : effacé à l'anonymisation, la conversation reste lisible.
        $this->listen(UserAnonymized::class, fn (UserAnonymized $event) => $this->app->make(MessagingActions::class)->eraseMessagesOf($event->userId));
    }
}
