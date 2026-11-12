<?php

namespace Kintai\Bundles\Messaging\Tests;

use App\Bundles\BundleManager;
use App\Bundles\DefaultPermissionGrant;
use App\Models\Store;
use App\Models\User;
use Kintai\Bundles\Messaging\MessagingActions;
use Kintai\Bundles\Messaging\Models\MessageThread;
use LogicException;

/**
 * Fond commun des tests de permissions : deux magasins, le bundle activé avec ses droits par défaut, des utilisateurs à
 * droits choisis. À utiliser avec `CreatesAccess`, `InstallsBundles` et `RefreshDatabase`.
 */
trait PermissionFixtures
{
    private Store $a;

    private Store $b;

    private function setUpMessaging(): void
    {
        $this->seedRoles();
        $this->a = Store::factory()->create(['name' => 'Alpha']);
        $this->b = Store::factory()->create(['name' => 'Beta']);
        $this->enableBundle('messaging');
        app(DefaultPermissionGrant::class)->grantOnFirstActivation(app(BundleManager::class)->find('messaging') ?? throw new LogicException);
    }

    /**
     * @return list<string>
     */
    private function allPermissions(): array
    {
        return ['messaging.view', 'messaging.send', 'messaging.create'];
    }

    /**
     * Membre d'un magasin avec un rôle sur mesure.
     *
     * @param  list<string>  $permissions
     */
    private function userWith(array $permissions, ?Store $store = null, bool $allStores = false): User
    {
        $store ??= $this->a;
        $user = User::factory()->create();
        $store->members()->attach($user);
        $user->assignRole($this->customRole($permissions, $allStores ? $permissions : []), $store);

        return $user;
    }

    private function actions(): MessagingActions
    {
        return app(MessagingActions::class);
    }

    /**
     * Une conversation du magasin Alpha, ouverte par Alice (employée par défaut) vers $to.
     */
    private function threadTo(User $to, ?User $from = null): MessageThread
    {
        $from ??= $this->employeeOf($this->a, ['name' => 'Alice']);

        return $this->actions()->start($from, $this->a->id, 'Subject', 'Hello', [$to->id]);
    }
}
