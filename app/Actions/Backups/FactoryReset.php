<?php

namespace App\Actions\Backups;

use App\Actions\License\DeactivateLicense;
use App\Backups\BackupException;
use App\Backups\BackupLock;
use App\Backups\BackupManager;
use App\Backups\PasswordConfirmation;
use App\Install\Installer;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Réinitialisation d'usine (Owner seulement) : efface toute la base (comptes, rôles, réglages, journal) et revient à
 * l'assistant d'installation (`/install`).
 *
 * Mêmes garde-fous que `ResetApplicationData` : le mot de passe de l'Owner et la phrase `FACTORY RESET` à recopier. Une
 * sauvegarde est faite juste avant, sous le verrou des opérations lourdes ; si elle échoue, rien n'est touché.
 *
 * Effets : toutes les tables sont supprimées puis recréées par les migrations (sans données ; les rôles reviennent à
 * l'installation), les fichiers téléversés (avatars et dossiers déclarés par les bundles) sont supprimés parce qu'ils
 * appartiennent aux comptes effacés (la sauvegarde les contient), le verrou d'installation est retiré et le cache vidé.
 * Ne sont JAMAIS touchés : `.env`, `APP_KEY` et le dossier des sauvegardes (ils servent à récupérer).
 *
 * Le journal d'activité disparaît avec la base : aucune entrée n'est possible, l'opération est donc écrite dans le log
 * Laravel (acteur et sauvegarde).
 */
class FactoryReset
{
    /** Phrase à recopier pour confirmer la réinitialisation d'usine. */
    public const CONFIRMATION = 'FACTORY RESET';

    public function __construct(
        private readonly BackupManager $manager,
        private readonly PasswordConfirmation $passwords,
        private readonly Installer $installer,
    ) {}

    /**
     * @return string Identifiant de la sauvegarde préalable.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws BackupException
     */
    public function handle(User $actor, string $confirmation, string $password): string
    {
        if (! Gate::forUser($actor)->allows('manage-settings')) {
            throw new AuthorizationException;
        }

        if ($confirmation !== self::CONFIRMATION) {
            throw ValidationException::withMessages(['confirmation' => __('Type :text to confirm.', ['text' => self::CONFIRMATION])]);
        }

        $this->passwords->verify($actor, $password);

        return $this->run($actor);
    }

    /**
     * Sauvegarde puis réinitialise, sans contrôle d'accès : réservé à l'appelant qui l'a déjà fait (`handle()`, ou la
     * commande `kintai:factory-reset` dans un terminal, sans utilisateur).
     *
     * @throws BackupException
     */
    public function run(?User $actor = null): string
    {
        // L'identité de l'acteur est lue avant l'effacement.
        $who = $actor === null ? 'terminal' : ['id' => $actor->id, 'email' => $actor->email];

        return BackupLock::run($this->manager->directory(), function () use ($who): string {
            $backup = $this->manager->create(__('Automatic backup before the factory reset'), BackupManager::TRIGGER_RESET);

            // Libère le siège de licence auprès du serveur (meilleur effort : un réseau absent ne bloque jamais la réinitialisation).
            app(DeactivateLicense::class)->release();

            $this->rebuildDatabase();
            $this->deleteUploads();
            $this->deleteLock();
            Cache::flush();

            Log::notice('Factory reset performed.', ['actor' => $who, 'backup' => $backup->id]);

            return $backup->id;
        });
    }

    /**
     * @throws BackupException
     */
    private function rebuildDatabase(): void
    {
        try {
            $status = Artisan::call('migrate:fresh', ['--force' => true]);
        } catch (Throwable $e) {
            report($e);

            throw new BackupException(__('The factory reset failed while rebuilding the database. The backup made just before can be restored.'));
        }

        if ($status !== 0) {
            Log::error('Factory reset: migrate:fresh failed: '.trim(Artisan::output()));

            throw new BackupException(__('The factory reset failed while rebuilding the database. The backup made just before can be restored.'));
        }
    }

    /** Vide les dossiers de fichiers téléversés sans supprimer les dossiers eux-mêmes. */
    private function deleteUploads(): void
    {
        foreach ($this->manager->fileAreas() as $directory) {
            if (is_dir($directory)) {
                File::deleteDirectory($directory, preserve: true);
            }
        }
    }

    /**
     * @throws BackupException
     */
    private function deleteLock(): void
    {
        $lock = $this->installer->lockPath();

        if (is_file($lock) && ! @unlink($lock)) {
            throw new BackupException(__('The installation lock could not be removed. Delete the file storage/app/installed by hand.'));
        }
    }
}
