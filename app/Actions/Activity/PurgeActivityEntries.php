<?php

namespace App\Actions\Activity;

use App\Activity\ActivityLogger;
use App\Models\ActivityEntry;
use App\Models\User;
use App\Settings\AppSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Purge du journal d'activité : manuelle (Owner, avec choix de conservation) ou automatique (rétention configurée).
 */
class PurgeActivityEntries
{
    /** Durées de conservation proposées à la purge manuelle ; 0 = tout supprimer. */
    public const CHOICES = [0, 7, 30, 90, 180, 365];

    public function __construct(private readonly ActivityLogger $logger) {}

    /**
     * Jours de conservation de la purge automatique (réglage `activity.retention_days`) ; 0 = illimité.
     */
    public static function retentionDays(): int
    {
        return app(AppSettings::class)->activityRetentionDays();
    }

    /**
     * Purge manuelle, réservée à l'Owner ; journalisée après coup avec le nombre d'entrées supprimées.
     *
     * @param  int  $keepDays  Jours à conserver (0 = tout supprimer)
     * @return int Nombre d'entrées supprimées
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, int $keepDays): int
    {
        if (! Gate::forUser($actor)->allows('manage-roles')) {
            throw new AuthorizationException;
        }

        if (! in_array($keepDays, self::CHOICES, true)) {
            throw ValidationException::withMessages(['keep_days' => __('Select a retention period.')]);
        }

        $deleted = $this->deleteOlderThan($keepDays);

        $this->logger->record('activity.purged', null, null, ['kept_days' => $keepDays, 'deleted' => $deleted], $actor);

        return $deleted;
    }

    /**
     * Purge automatique (commande quotidienne), selon la rétention configurée ; non journalisée.
     *
     * @return int Nombre d'entrées supprimées
     */
    public function automatic(): int
    {
        $days = self::retentionDays();

        return $days === 0 ? 0 : $this->deleteOlderThan($days);
    }

    private function deleteOlderThan(int $keepDays): int
    {
        $query = ActivityEntry::query();

        if ($keepDays > 0) {
            $query->where('created_at', '<', now()->subDays($keepDays));
        }

        return $query->delete();
    }
}
