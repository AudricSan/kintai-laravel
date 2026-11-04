<?php

namespace App\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Supprime les sessions enregistrées d'un utilisateur (pilote `database` uniquement).
 */
class RevokeUserSessions
{
    public function handle(User $user): void
    {
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        }
    }
}
