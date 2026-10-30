<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\CalendarConnection;
use App\Models\RouteSlug;
use App\Models\Casts\HexColor;
use App\Models\Concerns\FlushesRequestMemo;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Compte utilisateur, et utilisateur du garde de session de Laravel (config/auth.php). Le hash du mot de passe est dans
 * la colonne password_hash.
 */
class User extends Authenticatable
{
    use FlushesRequestMemo;

    protected $table = 'users';
    protected $guarded = []; // Allow mass assignment for now to simplify migration
    public $timestamps = false;

    // Une couleur invalide en base est remplacée à la lecture (voir HexColor).
    protected $casts = ['color' => HexColor::class . ':#3B82F6'];

    public function getAuthPassword(): string
    {
        return (string) $this->password_hash;
    }

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    /**
     * Finds a user by their ID.
     * @param int $id
     * @return array|null
     */
    public static function findById(int $id): ?array
    {
        $user = static::find($id);
        return $user ? $user->toArray() : null;
    }

    /**
     * Finds a user by their email address.
     * @param string $email
     * @return array|null
     */
    public static function findByEmail(string $email): ?array
    {
        $user = static::where('email', $email)->first();
        return $user ? $user->toArray() : null;
    }

    public static function findByEmployeeCode(string $code): ?array
    {
        $user = static::where('employee_code', $code)->first();
        return $user ? $user->toArray() : null;
    }

    /**
     * Retrieves all users.
     * @return array
     */
    public static function findAll(): array
    {
        return static::all()->toArray();
    }

    /**
     * Compte les utilisateurs actifs (is_active = 1, deleted_at IS NULL).
     */
    public static function countActive(): int
    {
        return static::where('is_active', 1)
            ->whereNull('deleted_at')
            ->count();
    }

    /**
     * Saves a user record. Creates if no ID, updates if ID exists.
     * @param array $userData
     * @return array The saved user data.
     */
    public static function put(array $userData): array
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
            $user = static::findOrFail($userData['id']);
            $oldCode = $user->employee_code;
            $user->fill($userData);
            $user->save();
            static::recordEmployeeCodeChange((int) $user->id, $oldCode, $user->employee_code);
        } else {
            $user = static::create($userData);
            static::recordEmployeeCodeChange((int) $user->id, null, $user->employee_code);
        }
        
        return $user->toArray();
    }

    /**
     * Deletes a user by their ID.
     * @param int $id
     * @return int The number of deleted users (0 or 1).
     */
    public static function revokeRememberToken(int $userId): void
    {
        static::whereKey($userId)->update(['remember_token' => null]);
    }

    public static function remove(int $id): int
    {
        $user = static::find($id);
        if ($user) {
            static::forgetEmployeeCodes($id);
            // SQLite n'applique pas les cascades : les préférences de notification partent avec le compte.
            try {
                \App\Models\NotificationPreference::where('user_id', $id)->delete();
                \App\Models\WebPushSubscription::where('user_id', $id)->delete();
                // Liaison d'agenda externe, correspondances shift ↔ événement et file de synchronisation.
                CalendarConnection::removeAllFor($id);
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
    private static function recordEmployeeCodeChange(int $userId, mixed $oldCode, mixed $newCode): void
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

    private static function forgetEmployeeCodes(int $userId): void
    {
        try {
            RouteSlug::where('entity_type', 'employee')->where('entity_id', $userId)->delete();
        } catch (\Illuminate\Database\QueryException) {
            // Table route_slugs absente : rien à oublier.
        }
    }
}