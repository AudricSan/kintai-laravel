<?php

namespace App\Actions\Dashboard;

use App\Models\DashboardLayout;
use App\Models\User;

class ResetDashboardLayout
{
    /**
     * Revient aux défauts : plus aucune disposition personnelle active (elles sont conservées), la disposition du
     * rôle ou celle du registre s'applique.
     */
    public function handle(User $user, string $audience): void
    {
        DashboardLayout::query()->personal($user, $audience)->update(['is_active' => false]);
    }
}
