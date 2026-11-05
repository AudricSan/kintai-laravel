<?php

namespace App\Actions\Backups;

use App\Activity\ActivityLogger;
use App\Models\User;
use App\Settings\AppSettings;
use App\Settings\CoreSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Réglages de la sauvegarde automatique : activation et nombre de sauvegardes conservées (0 = illimité).
 */
class SaveBackupSettings
{
    public function __construct(private readonly AppSettings $settings, private readonly ActivityLogger $logger) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, bool $autoEnabled, mixed $maxKeep): void
    {
        if (! Gate::forUser($actor)->allows('manage-settings')) {
            throw new AuthorizationException;
        }

        $validated = Validator::make(['max_keep' => is_string($maxKeep) ? trim($maxKeep) : $maxKeep], [
            'max_keep' => ['required', 'integer', 'min:0', 'max:'.CoreSettings::BACKUP_MAX_KEEP_MAX],
        ], [], ['max_keep' => __('Backups to keep')])->validate();

        $keep = (int) $validated['max_keep'];
        $changed = [];

        if ($this->settings->backupAutoEnabled() !== $autoEnabled) {
            $changed[] = 'backup.auto_enabled';
        }

        if ($this->settings->backupMaxKeep() !== $keep) {
            $changed[] = 'backup.max_keep';
        }

        $this->settings->setMany(['backup.auto_enabled' => $autoEnabled, 'backup.max_keep' => $keep]);

        $this->logger->record('backup.settings_updated', null, null, ['keys' => $changed], $actor);
    }
}
