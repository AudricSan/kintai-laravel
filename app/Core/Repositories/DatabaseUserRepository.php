<?php

declare(strict_types=1);


namespace kintai\Core\Repositories;

use kintai\Domain\Eloquent\RouteSlug;
use kintai\Domain\Eloquent\User as EloquentUser;

final class DatabaseUserRepository implements UserRepositoryInterface
{
    public function __construct() {}

    /**
     * Finds a user by their ID.
     * @param int $id
     * @return array|null
     */
    public function findById(int $id): ?array
    {
        $user = EloquentUser::find($id);
        return $user ? $user->toArray() : null;
    }

    /**
     * Finds a user by their email address.
     * @param string $email
     * @return array|null
     */
    public function findByEmail(string $email): ?array
    {
        $user = EloquentUser::where('email', $email)->first();
        return $user ? $user->toArray() : null;
    }

    public function findByEmployeeCode(string $code): ?array
    {
        $user = EloquentUser::where('employee_code', $code)->first();
        return $user ? $user->toArray() : null;
    }

    /**
     * Retrieves all users.
     * @return array
     */
    public function findAll(): array
    {
        return EloquentUser::all()->toArray();
    }

    /**
     * Compte les utilisateurs actifs (is_active = 1, deleted_at IS NULL).
     */
    public function countActive(): int
    {
        return EloquentUser::where('is_active', 1)
            ->whereNull('deleted_at')
            ->count();
    }

    /**
     * Saves a user record. Creates if no ID, updates if ID exists.
     * @param array $userData
     * @return array The saved user data.
     */
    public function save(array $userData): array
    {
        // Langue vide = le compte suit la langue de l'instance (InstanceLocale). Toujours '' plutôt que null : la
        // colonne est NOT NULL sur les instances existantes. Un nouveau compte suit l'instance, quel que soit le
        // défaut historique de la colonne ('fr').
        if (array_key_exists('language', $userData) && $userData['language'] === null) {
            $userData['language'] = '';
        }
        if (empty($userData['id'])) {
            $userData += ['language' => ''];
        }
        if (!empty($userData['id'])) {
            $user = EloquentUser::findOrFail($userData['id']);
            $oldCode = $user->employee_code;
            $user->fill($userData);
            $user->save();
            $this->recordEmployeeCodeChange((int) $user->id, $oldCode, $user->employee_code);
        } else {
            $user = EloquentUser::create($userData);
            $this->recordEmployeeCodeChange((int) $user->id, null, $user->employee_code);
        }
        
        return $user->toArray();
    }

    /**
     * Deletes a user by their ID.
     * @param int $id
     * @return int The number of deleted users (0 or 1).
     */
    public function delete(int $id): int
    {
        $user = EloquentUser::find($id);
        if ($user) {
            $this->forgetEmployeeCodes($id);
            // SQLite n'applique pas les cascades : les préférences de notification partent avec le compte.
            try {
                \kintai\Domain\Eloquent\NotificationPreference::where('user_id', $id)->delete();
                \kintai\Domain\Eloquent\WebPushSubscription::where('user_id', $id)->delete();
                // Liaison d'agenda externe, correspondances shift ↔ événement et file de synchronisation.
                (new DatabaseCalendarSyncRepository())->deleteAllForUser($id);
            } catch (\Illuminate\Database\QueryException) {
                // Table absente (migration pas encore jouée) : la suppression du compte prime.
            }
            return $user->delete() ? 1 : 0;
        }
        return 0;
    }

    /**
     * Le numéro d'employé sert de segment d'URL (/admin/users/057/edit, voir EmployeeRouteBinder). Quel que soit
     * le chemin qui le modifie (formulaire, API, import), l'ancien numéro est conservé pour rediriger les anciens
     * liens en 301, et un numéro réattribué cesse de pointer vers son ancien titulaire.
     */
    private function recordEmployeeCodeChange(int $userId, mixed $oldCode, mixed $newCode): void
    {
        $old = $oldCode === null || $oldCode === '' ? null : (string) $oldCode;
        $new = $newCode === null || $newCode === '' ? null : (string) $newCode;
        if ($old === $new) {
            return;
        }

        try {
            if ($new !== null) {
                // Le numéro courant l'emporte sur tout historique : le sien comme celui d'un autre employé.
                RouteSlug::where('entity_type', 'employee')->where('slug', $new)->delete();
            }
            if ($old !== null && !RouteSlug::where('entity_type', 'employee')->where('slug', $old)->exists()) {
                RouteSlug::create([
                    'entity_type' => 'employee',
                    'entity_id'   => $userId,
                    'slug'        => $old,
                    'is_current'  => false,
                    'is_manual'   => false,
                    'created_at'  => date('Y-m-d H:i:s'),
                ]);
            }
        } catch (\Illuminate\Database\QueryException) {
            // Table route_slugs absente (migration pas encore jouée) : l'enregistrement de l'utilisateur prime.
        }
    }

    private function forgetEmployeeCodes(int $userId): void
    {
        try {
            RouteSlug::where('entity_type', 'employee')->where('entity_id', $userId)->delete();
        } catch (\Illuminate\Database\QueryException) {
            // Table route_slugs absente : rien à oublier.
        }
    }
}
