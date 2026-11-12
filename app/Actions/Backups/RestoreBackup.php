<?php

namespace App\Actions\Backups;

use App\Activity\ActivityLogger;
use App\Backups\BackupException;
use App\Backups\BackupInfo;
use App\Backups\BackupLock;
use App\Backups\BackupManager;
use App\Backups\BackupRestorer;
use App\Backups\PasswordConfirmation;
use App\Models\User;
use App\Settings\AppSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Restauration d'une sauvegarde (Owner seulement, mot de passe revérifié).
 *
 * Ordre : contrôle de l'archive (rien n'est touché si elle est refusée) ; sauvegarde de sécurité de l'état actuel ;
 * maintenance activée pour les autres utilisateurs ; base puis fichiers ; migrations si le schéma de l'application est plus
 * récent que la sauvegarde ; état de maintenance d'avant rétabli. Si la restauration échoue, la sauvegarde de sécurité est
 * restaurée à son tour et l'Owner est informé (avec son identifiant, s'il faut intervenir à la main).
 *
 * Les sessions, les rôles et les comptes reviennent aussi : l'appelant doit déconnecter l'Owner et le renvoyer à la connexion.
 */
class RestoreBackup
{
    public function __construct(
        private readonly BackupManager $manager,
        private readonly BackupRestorer $restorer,
        private readonly PasswordConfirmation $passwords,
        private readonly AppSettings $settings,
        private readonly ActivityLogger $logger,
    ) {}

    /**
     * @return array{safety: string, migrated: bool} Sauvegarde de sécurité créée ; migrations lancées ?
     *
     * @throws AuthorizationException
     * @throws ValidationException mot de passe refusé, sauvegarde inconnue
     * @throws BackupException
     */
    public function handle(User $actor, string $id, string $password): array
    {
        if (! Gate::forUser($actor)->allows('manage-settings')) {
            throw new AuthorizationException;
        }

        $this->passwords->verify($actor, $password);

        $backup = $this->manager->find($id)
            ?? throw ValidationException::withMessages(['backup' => __('This backup does not exist.')]);

        $manifest = $this->restorer->check($backup);

        return BackupLock::run($this->manager->directory(), function () use ($actor, $backup, $manifest): array {
            @ignore_user_abort(true);

            $safety = $this->manager->create(__('Automatic backup before restoring :backup', ['backup' => $backup->id]), BackupManager::TRIGGER_SAFETY);
            $maintenance = $this->settings->maintenanceEnabled();

            try {
                $this->settings->set('maintenance.enabled', true);
                $migrated = $this->restoreAndMigrate($backup);
            } catch (Throwable $e) {
                report($e);

                $rolledBack = false;

                try {
                    $this->restorer->restore($safety);
                    $this->afterRestore();
                    $rolledBack = true;
                } catch (Throwable $rollbackError) {
                    report($rollbackError);
                }

                $this->setMaintenance($maintenance);

                throw new BackupException($rolledBack
                    ? __('The restoration failed. The application was put back in its previous state. A safety backup is available: :backup', ['backup' => $safety->id])
                    : __('The restoration failed and the previous state could not be put back automatically. Restore this safety backup: :backup', ['backup' => $safety->id]), 0, $e);
            }

            $this->setMaintenance($maintenance);
            $this->log($actor, $backup->id, $safety->id, $migrated, (string) ($manifest['created_at'] ?? ''));

            return ['safety' => $safety->id, 'migrated' => $migrated];
        });
    }

    /**
     * @return bool Des migrations ont-elles été lancées ?
     */
    private function restoreAndMigrate(BackupInfo $backup): bool
    {
        $this->restorer->restore($backup);
        $this->afterRestore();

        if (! $this->hasPendingMigrations()) {
            return false;
        }

        $status = Artisan::call('migrate', ['--force' => true]);

        if ($status !== 0) {
            throw new BackupException(__('The migrations could not be run after the restoration.'));
        }

        return true;
    }

    /** Ce que la restauration a pu laisser périmé : réglages en cache, cache applicatif. */
    private function afterRestore(): void
    {
        $this->settings->flush();

        try {
            Cache::flush();
        } catch (Throwable) {
            // Cache indisponible : rien de périmé à purger.
        }
    }

    private function hasPendingMigrations(): bool
    {
        $migrator = app('migrator');
        $files = array_keys($migrator->getMigrationFiles(array_merge($migrator->paths(), [database_path('migrations')])));

        return array_diff($files, $migrator->getRepository()->getRan()) !== [];
    }

    private function setMaintenance(bool $enabled): void
    {
        try {
            $this->settings->set('maintenance.enabled', $enabled);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function log(User $actor, string $backup, string $safety, bool $migrated, string $createdAt): void
    {
        // La base restaurée ne connaît peut-être pas ce compte : l'entrée serait refusée par la clé étrangère.
        $known = DB::table('users')->where('id', $actor->getKey())->exists();

        if (! $known) {
            Auth::forgetGuards();
        }

        $this->logger->record('backup.restored', null, null, [
            'backup' => $backup,
            'safety_backup' => $safety,
            'migrated' => $migrated,
            'backup_created_at' => $createdAt,
            'actor_name' => $actor->name,
        ], $known ? $actor : null);
    }
}
