<?php

namespace Kintai\Bundles\ShiftClaim;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Kintai\Bundles\ShiftClaim\Livewire\Index;
use Kintai\Bundles\ShiftClaim\Livewire\Manage;
use Kintai\Bundles\ShiftClaim\Models\ShiftClaim;
use Kintai\Contracts\Bundle\BundleServiceProvider;
use Kintai\Contracts\Events\ShiftCreated;
use Kintai\Contracts\Events\ShiftDeleted;
use Kintai\Contracts\Events\ShiftReplaced;
use Kintai\Contracts\Events\ShiftUpdated;
use Kintai\Contracts\Events\UserAnonymized;
use Kintai\Contracts\Navigation\NavigationItem;
use Kintai\Contracts\Navigation\NavigationRegistry;
use Kintai\Contracts\Notifications\NotificationType;
use Kintai\Contracts\Requests\PendingRequestProvider;
use Livewire\Livewire;

/**
 * Reprise de shifts ouverts : les shifts ouverts (sans employé) et ceux qu'un gérant propose à la bourse sont offerts
 * aux employés du magasin, qui candidatent ; un gérant retient une candidature et le Core attribue le shift (opération
 * `ShiftReplacer` du contrat). Droits `open_shifts.view/publish/approve` (voir `ClaimAccess`), fonctionnalité de magasin
 * `open_shifts`.
 */
class ShiftClaimServiceProvider extends BundleServiceProvider
{
    public const SLUG = 'shift-claim';

    public const FEATURE = 'open_shifts';

    public const PUBLISHED = 'open_shift_published';

    public const SUBMITTED = 'shift_claim_submitted';

    public const APPROVED = 'shift_claim_approved';

    public const REJECTED = 'shift_claim_rejected';

    public const WITHDRAWN = 'shift_claim_withdrawn';

    public function slug(): string
    {
        return self::SLUG;
    }

    public function name(): string
    {
        return 'Open shifts';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function description(): string
    {
        return 'Open shifts: open and offered shifts are proposed to employees, who apply; a manager picks one and the schedule is updated.';
    }

    public function requiredContract(): string
    {
        return '^1.3';
    }

    protected function bootBundle(): void
    {
        if ($this->isEnabled()) {
            Livewire::component('shift-claim.index', Index::class);
            Livewire::component('shift-claim.manage', Manage::class);
        }

        // Les gérants gèrent la bourse ; les employés candidatent sans permission (libre-service).
        $this->permissions('open_shifts', ['view', 'publish', 'approve'], [
            'manager' => ['view', 'publish', 'approve'],
        ]);
        $this->storeFeature(self::FEATURE, 'Open shifts');

        $hasStore = fn (Authenticatable $user): bool => $user instanceof User && $this->app->make(ClaimAccess::class)->memberStores($user) !== [];

        $this->navigation(
            new NavigationItem(
                key: 'shift-claim', label: 'Open shifts', route: 'shift-claim.index', icon: 'hand-raised',
                section: NavigationRegistry::SECTION_REQUESTS, order: 30, visible: $hasStore, current: ['shift-claim.index'],
            ),
            new NavigationItem(
                key: 'shift-claim-manage', label: 'Shift applications', route: 'shift-claim.manage', icon: 'clipboard-document-check',
                section: NavigationRegistry::SECTION_REQUESTS, order: 31, permission: 'open_shifts.view', current: ['shift-claim.manage'],
            ),
        );
        $this->pendingRequests(new PendingRequestProvider(
            'shift-claim', 'Shift applications', 'shift-claim.index',
            fn (Authenticatable $user): int => $user instanceof User ? $this->app->make(ClaimAccess::class)->pendingFor($user)->count() : 0,
        ));
        $this->notificationTypes(
            new NotificationType(self::PUBLISHED, 'Open shift', 'planning', 'megaphone', push: true, referenceType: 'open_shift'),
            new NotificationType(self::SUBMITTED, 'Shift application', 'requests', 'hand-raised', push: true, referenceType: 'open_shift'),
            new NotificationType(self::APPROVED, 'Shift application approved', 'requests', 'check-circle', push: true, mail: true, referenceType: 'shift'),
            new NotificationType(self::REJECTED, 'Shift application not selected', 'requests', 'x-circle', push: true, mail: true, referenceType: 'open_shift'),
            new NotificationType(self::WITHDRAWN, 'Shift no longer open', 'requests', 'no-symbol', push: true, referenceType: 'open_shift'),
        );
        foreach ([
            'shift_claim.created' => 'Shift application sent',
            'shift_claim.withdrawn' => 'Shift application withdrawn',
            'shift_claim.approved' => 'Shift application approved',
            'shift_claim.rejected' => 'Shift application not selected',
            'shift_claim.published' => 'Shift offered to applications',
            'shift_claim.unpublished' => 'Shift withdrawn from applications',
        ] as $key => $label) {
            $this->activityAction($key, $label, 'Open shifts');
        }

        // La bourse suit le planning du Core : shift ouvert annoncé, shift disparu ou réaffecté retiré.
        $this->listen(ShiftCreated::class, fn (ShiftCreated $event) => $this->app->make(ShiftChanges::class)->created($event));
        $this->listen(ShiftUpdated::class, fn (ShiftUpdated $event) => $this->app->make(ShiftChanges::class)->updated($event));
        $this->listen(ShiftDeleted::class, fn (ShiftDeleted $event) => $this->app->make(ShiftChanges::class)->deleted($event));
        $this->listen(ShiftReplaced::class, fn (ShiftReplaced $event) => $this->app->make(ShiftChanges::class)->replaced($event));

        // La note d'une candidature est une donnée personnelle : effacée avec l'anonymisation du compte.
        $this->listen(UserAnonymized::class, fn (UserAnonymized $event) => ShiftClaim::query()->where('user_id', $event->userId)->update(['note' => null]));
    }
}
