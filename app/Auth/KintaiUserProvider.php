<?php

declare(strict_types=1);

namespace App\Auth;

use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Fournisseur d'utilisateurs du garde de session (config/auth.php, pilote « kintai ») : retrouve le compte d'une
 * connexion par e-mail, ou par code employé + code magasin, et vérifie son mot de passe. Auth::attempt() s'appuie
 * dessus.
 *
 * Un compte inconnu, inactif ou supprimé, un magasin inexistant ou un employé hors de ce magasin dépensent un calcul
 * bcrypt factice : sans cela ils répondraient bien plus vite qu'un vrai compte avec un mauvais mot de passe (qui, lui,
 * paie un password_verify), et cette différence de durée permettrait de tester l'existence d'un compte.
 */
final class KintaiUserProvider extends EloquentUserProvider
{
    /** @param array<string, mixed> $credentials email | (employee_code + store_code), et password */
    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        $user = isset($credentials['employee_code'])
            ? $this->byEmployeeCode((string) $credentials['employee_code'], (string) ($credentials['store_code'] ?? ''))
            : $this->byEmail((string) ($credentials['email'] ?? ''));

        if ($user === null || !$user->is_active || $user->deleted_at !== null) {
            PasswordHasher::burn((string) ($credentials['password'] ?? ''));

            return null;
        }

        return $user;
    }

    /** @param array<string, mixed> $credentials */
    public function validateCredentials(Authenticatable $user, array $credentials): bool
    {
        return password_verify((string) ($credentials['password'] ?? ''), $user->getAuthPassword());
    }

    /** Les hashs de l'application gardent leur coût d'origine (PasswordHasher) : pas de ré-empreinte à la connexion. */
    public function rehashPasswordIfRequired(Authenticatable $user, array $credentials, bool $force = false): void
    {
    }

    private function byEmail(string $email): ?User
    {
        return User::query()->where('email', $email)->first();
    }

    private function byEmployeeCode(string $employeeCode, string $storeCode): ?User
    {
        $store = Store::findByCode(strtoupper(trim($storeCode)));
        $user  = $store === null ? null : User::query()->where('employee_code', trim($employeeCode))->first();
        if ($user === null || StoreUser::findMembership((int) $store['id'], (int) $user->id) === null) {
            return null;
        }

        return $user;
    }
}
