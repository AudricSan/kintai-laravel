<?php

namespace App\Actions\Dashboard;

use App\Models\DashboardLayout;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class ActivateDashboardLayout
{
    /**
     * Une seule disposition personnelle active par tableau de bord.
     *
     * @throws AuthorizationException
     */
    public function handle(User $user, DashboardLayout $layout): void
    {
        if ($layout->user_id !== $user->id) {
            throw new AuthorizationException;
        }

        DB::transaction(function () use ($user, $layout): void {
            DashboardLayout::query()->personal($user, $layout->dashboard)->update(['is_active' => false]);
            $layout->forceFill(['is_active' => true])->save();
        });
    }
}
