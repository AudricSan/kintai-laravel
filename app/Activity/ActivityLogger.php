<?php

namespace App\Activity;

use App\Models\ActivityEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Écrit une ligne du journal d'activité. Point d'entrée du Core et des bundles :
 *
 *     app(ActivityLogger::class)->record('timeoff.approved', $request, $storeId, ['employee' => $name]);
 *
 * - l'acteur par défaut est l'utilisateur connecté (null pour une tâche planifiée) ;
 * - l'adresse IP vient de `request()->ip()` : elle suit la configuration des proxys de confiance de Laravel et ne lit
 *   jamais un en-tête brut (`X-Forwarded-For` ne peut donc pas être usurpé sans proxy de confiance) ;
 * - mots de passe, jetons et secrets sont retirés des propriétés, à tous les niveaux ;
 * - un échec d'écriture est signalé (`report()`) mais ne casse jamais l'action qui est journalisée.
 *
 * Le bundle déclare aussi le libellé de ses actions dans `ActivityActionRegistry`.
 */
class ActivityLogger
{
    /** Fragments de clé (insensibles à la casse) dont la valeur n'est jamais journalisée. */
    private const FORBIDDEN_KEY_FRAGMENTS = ['password', 'passwd', 'token', 'secret', 'recovery_code', 'two_factor', 'api_key', 'authorization', 'cookie'];

    /**
     * @param  array<string, mixed>  $properties  Contexte lisible : noms, avant / après…
     */
    public function record(string $action, ?Model $subject = null, ?int $storeId = null, array $properties = [], ?User $actor = null): ?ActivityEntry
    {
        return $this->write($action, $subject?->getMorphClass(), $subject?->getKey(), $storeId, $properties, $actor);
    }

    /**
     * Comme `record()`, pour une cible qui n'existe plus (supprimée) : on ne dispose que de son type et de son identifiant.
     *
     * @param  array<string, mixed>  $properties
     */
    public function recordFor(string $action, string $subjectType, int $subjectId, ?int $storeId = null, array $properties = [], ?User $actor = null): ?ActivityEntry
    {
        return $this->write($action, $subjectType, $subjectId, $storeId, $properties, $actor);
    }

    /**
     * Retire récursivement les clés interdites.
     *
     * @param  array<array-key, mixed>  $properties
     * @return array<array-key, mixed>
     */
    public static function sanitize(array $properties): array
    {
        $clean = [];

        foreach ($properties as $key => $value) {
            if (is_string($key) && self::isForbidden($key)) {
                continue;
            }

            $clean[$key] = is_array($value) ? self::sanitize($value) : $value;
        }

        return $clean;
    }

    private static function isForbidden(string $key): bool
    {
        $key = strtolower($key);

        foreach (self::FORBIDDEN_KEY_FRAGMENTS as $fragment) {
            if (str_contains($key, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function write(string $action, ?string $subjectType, int|string|null $subjectId, ?int $storeId, array $properties, ?User $actor): ?ActivityEntry
    {
        try {
            $actor ??= Auth::user();
            $request = request();
            $userAgent = $request->userAgent();

            return ActivityEntry::query()->create([
                'actor_id' => $actor?->getKey(),
                'action' => mb_substr($action, 0, 100),
                'subject_type' => $subjectType === null ? null : mb_substr($subjectType, 0, 100),
                'subject_id' => $subjectId === null ? null : (int) $subjectId,
                'store_id' => $storeId,
                'properties' => array_filter(self::sanitize($properties), fn (mixed $value): bool => $value !== null) ?: null,
                'ip_address' => $request->ip(),
                'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 255),
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
