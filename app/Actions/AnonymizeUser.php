<?php

namespace App\Actions;

use App\Models\IcalToken;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Kintai\Contracts\Events\UserAnonymized;

/**
 * Efface l'identité d'un utilisateur puis le met à la corbeille. L'historique (shifts…) reste rattaché.
 */
class AnonymizeUser
{
    public function handle(User $user): void
    {
        $avatarPath = $user->avatar_path;

        DB::transaction(function () use ($user, $avatarPath): void {
            $user->forceFill([
                'first_name' => '[Supprimé]',
                'last_name' => '',
                'name' => __('Deleted user'),
                'email' => 'deleted-'.$user->id.'@kintai.invalid',
                'employee_code' => null,
                'furigana_first_name' => null,
                'furigana_last_name' => null,
                'password' => Hash::make(Str::random(40)),
                'avatar_path' => null,
                'share_email' => false,
                'share_phone' => false,
                'share_mobile_phone' => false,
                'is_active' => false,
                'must_change_password' => false,
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
                'remember_token' => Str::random(60),
            ])->save();

            $user->profile()->delete();
            $user->passkeys()->delete();
            $user->webPushSubscriptions()->delete();
            $user->devicePushTokens()->delete();
            RoleAssignment::query()->where('user_id', $user->id)->delete();
            IcalToken::query()->where('user_id', $user->id)->delete();

            app(RevokeUserSessions::class)->handle($user);

            $user->delete();

            DB::afterCommit(function () use ($user, $avatarPath): void {
                if ($avatarPath !== null && $avatarPath !== '') {
                    Storage::disk('local')->delete($avatarPath);
                }

                UserAnonymized::dispatch($user->id);
            });
        });
    }
}
