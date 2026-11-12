<?php

namespace App\Actions\Dashboard;

use App\Models\DashboardLayout;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class DeleteDashboardLayout
{
    /**
     * Supprime une disposition personnelle (jamais celle d'un autre utilisateur ni celle d'un rôle).
     *
     * @throws AuthorizationException
     */
    public function handle(User $user, DashboardLayout $layout): void
    {
        if ($layout->user_id !== $user->id) {
            throw new AuthorizationException;
        }

        $layout->delete();
    }
}
