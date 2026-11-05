<?php

namespace Kintai\Bundles\ShiftSwap;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Kintai\Bundles\ShiftSwap\Livewire\Create;
use Kintai\Bundles\ShiftSwap\Livewire\EmployeeWidget;
use Kintai\Bundles\ShiftSwap\Livewire\Index;
use Kintai\Bundles\ShiftSwap\Livewire\Manage;
use Kintai\Bundles\ShiftSwap\Livewire\ManagerWidget;
use Kintai\Bundles\ShiftSwap\Models\ShiftSwapRequest;
use Kintai\Contracts\Bundle\BundleServiceProvider;
use Kintai\Contracts\Dashboard\DashboardWidget;
use Kintai\Contracts\Dashboard\WidgetSize;
use Kintai\Contracts\Events\UserAnonymized;
use Kintai\Contracts\Navigation\NavigationItem;
use Kintai\Contracts\Navigation\NavigationRegistry;
use Kintai\Contracts\Notifications\NotificationType;
use Kintai\Contracts\Requests\PendingRequestProvider;
use Livewire\Livewire;

/**
 * Échanges de shifts : un employé propose l'un de ses shifts à un collègue, le collègue répond, un gérant valide et le
 * Core applique l'échange (opération `ShiftExchanger` du contrat). Un gérant peut aussi appliquer d'emblée un échange.
 * Droits `swaps.view/create/update/approve/delete` (voir `SwapAccess`), fonctionnalité de magasin `swaps`.
 */
class ShiftSwapServiceProvider extends BundleServiceProvider
{
    public const SLUG = 'shift-swap';

    public const FEATURE = 'swaps';

    public const REQUESTED = 'swap_requested';

    public const PEER_ACCEPTED = 'swap_peer_accepted';

    public const PEER_REFUSED = 'swap_peer_refused';

    public const TO_APPROVE = 'swap_to_approve';

    public const ACCEPTED = 'swap_accepted';

    public const REFUSED = 'swap_refused';

    public const CANCELLED = 'swap_cancelled';

    public const APPLIED = 'swap_applied';

    public const RESTORED = 'swap_cancelled_restored';

    public function slug(): string
    {
        return self::SLUG;
    }

    public function name(): string
    {
        return 'Shift swaps';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function description(): string
    {
        return 'Shift swaps: an employee proposes a swap to a colleague, a manager approves it and the schedule is updated.';
    }

    public function requiredContract(): string
    {
        return '^1.3';
    }

    protected function bootBundle(): void
    {
        if ($this->isEnabled()) {
            Livewire::component('shift-swap.index', Index::class);
            Livewire::component('shift-swap.create', Create::class);
            Livewire::component('shift-swap.manage', Manage::class);
            Livewire::component('shift-swap.manager-widget', ManagerWidget::class);
            Livewire::component('shift-swap.employee-widget', EmployeeWidget::class);
        }

        // Les gérants gèrent les échanges ; les employés proposent et répondent sans permission (libre-service).
        $this->permissions('swaps', ['view', 'create', 'update', 'approve', 'delete'], [
            'manager' => ['view', 'create', 'update', 'approve', 'delete'],
        ]);
        $this->storeFeature(self::FEATURE, 'Shift swaps');

        $hasStore = fn (Authenticatable $user): bool => $user instanceof User && $this->app->make(SwapAccess::class)->memberStores($user) !== [];

        $this->navigation(
            new NavigationItem(
                key: 'shift-swap', label: 'Shift swaps', route: 'shift-swap.index', icon: 'arrows-right-left',
                section: NavigationRegistry::SECTION_REQUESTS, order: 25, visible: $hasStore, current: ['shift-swap.index', 'shift-swap.create'],
            ),
            new NavigationItem(
                key: 'shift-swap-manage', label: 'Swap requests', route: 'shift-swap.manage', icon: 'clipboard-document-check',
                section: NavigationRegistry::SECTION_REQUESTS, order: 26, permission: 'swaps.view', current: ['shift-swap.manage'],
            ),
        );
        $this->pendingRequests(new PendingRequestProvider(
            'shift-swap', 'Shift swaps', 'shift-swap.index',
            fn (Authenticatable $user): int => $user instanceof User ? $this->app->make(SwapAccess::class)->pendingFor($user)->count() : 0,
        ));
        $this->widgets(
            new DashboardWidget(
                key: 'shift-swap.manager', label: 'Swaps to approve', audience: DashboardWidget::MANAGER, component: 'shift-swap.manager-widget',
                permission: 'swaps.view', storeFeature: self::FEATURE, size: WidgetSize::Medium, order: 55,
            ),
            new DashboardWidget(
                key: 'shift-swap.employee', label: 'Swaps awaiting my answer', audience: DashboardWidget::EMPLOYEE, component: 'shift-swap.employee-widget',
                storeFeature: self::FEATURE, size: WidgetSize::Small, order: 55,
            ),
        );
        $this->notificationTypes(
            new NotificationType(self::REQUESTED, 'Swap request', 'requests', 'arrows-right-left', push: true, referenceType: 'swap'),
            new NotificationType(self::PEER_ACCEPTED, 'Swap accepted by colleague', 'requests', 'arrows-right-left', push: true, referenceType: 'swap'),
            new NotificationType(self::PEER_REFUSED, 'Swap declined by colleague', 'requests', 'arrows-right-left', push: true, referenceType: 'swap'),
            new NotificationType(self::TO_APPROVE, 'Swap to approve', 'requests', 'arrows-right-left', push: true, referenceType: 'swap'),
            new NotificationType(self::ACCEPTED, 'Swap approved', 'requests', 'check-circle', push: true, mail: true, referenceType: 'swap'),
            new NotificationType(self::REFUSED, 'Swap refused', 'requests', 'x-circle', push: true, referenceType: 'swap'),
            new NotificationType(self::CANCELLED, 'Swap cancelled', 'requests', 'x-circle', push: true, referenceType: 'swap'),
            new NotificationType(self::APPLIED, 'Swap applied by a manager', 'planning', 'arrows-right-left', push: true, mail: true, referenceType: 'swap'),
            new NotificationType(self::RESTORED, 'Swap undone, shifts restored', 'planning', 'arrow-uturn-left', push: true, mail: true, referenceType: 'swap'),
        );
        foreach ([
            'swap.created' => 'Swap requested',
            'swap.created_by_manager' => 'Swap applied by a manager',
            'swap.peer_accepted' => 'Swap accepted by colleague',
            'swap.peer_refused' => 'Swap declined by colleague',
            'swap.cancelled' => 'Swap cancelled',
            'swap.approved' => 'Swap approved',
            'swap.refused' => 'Swap refused',
            'swap.updated' => 'Swap updated',
            'swap.deleted' => 'Swap deleted',
        ] as $key => $label) {
            $this->activityAction($key, $label, 'Shift swaps');
        }

        // Le motif est une donnée personnelle : effacé avec l'anonymisation du compte (les shifts et dates restent).
        $this->listen(UserAnonymized::class, fn (UserAnonymized $event) => ShiftSwapRequest::query()
            ->where(fn ($q) => $q->where('requester_id', $event->userId)->orWhere('target_user_id', $event->userId))
            ->update(['reason' => null]));
    }
}
