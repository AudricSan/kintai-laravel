<?php

namespace App\Actions\Dashboard;

use App\Models\DashboardLayout;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

class ClearRoleDashboardLayout
{
    /**
     * Retire la disposition imposée à un rôle pour un tableau de bord. Réservé à l'Owner.
     *
     * @throws AuthorizationException
     */
    public function handle(User $actor, Role $role, string $audience): void
    {
        if (! Gate::forUser($actor)->allows('manage-roles')) {
            throw new AuthorizationException;
        }

        DashboardLayout::query()->forRole($role, $audience)->delete();
    }
}
