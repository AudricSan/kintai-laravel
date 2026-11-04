<?php

namespace App\Actions\Fortify;

use App\Models\Store;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Authentification par e-mail, ou par code employé et code magasin.
 * Tout refus renvoie null (message unique côté Fortify) après un calcul de hachage factice,
 * pour que le temps de réponse ne révèle ni l'existence du compte ni celle du magasin.
 */
class AuthenticateUser
{
    private static ?string $dummyHash = null;

    public function __invoke(Request $request): ?User
    {
        $login = trim((string) $request->input('login'));
        $storeCode = trim((string) $request->input('store_code'));
        $password = (string) $request->input('password');

        $user = $storeCode !== ''
            ? $this->findByEmployeeCode($login, $storeCode)
            : User::query()->where('email', Str::lower($login))->first();

        if ($user === null || ! $user->is_active) {
            Hash::check($password, self::dummyHash());

            return null;
        }

        if (! Hash::check($password, $user->password)) {
            return null;
        }

        if (mb_strlen($password) < 8) {
            $user->forceFill(['must_change_password' => true])->save();
        }

        return $user;
    }

    private function findByEmployeeCode(string $employeeCode, string $storeCode): ?User
    {
        $store = Store::query()->where('code', Str::upper($storeCode))->first();

        if ($store === null || $employeeCode === '') {
            return null;
        }

        $user = User::query()->where('employee_code', $employeeCode)->first();

        if ($user === null) {
            return null;
        }

        $isMember = DB::table('store_user')
            ->where('store_id', $store->id)
            ->where('user_id', $user->id)
            ->exists();

        return $isMember ? $user : null;
    }

    private static function dummyHash(): string
    {
        return self::$dummyHash ??= Hash::make('dummy-password-for-timing');
    }
}
