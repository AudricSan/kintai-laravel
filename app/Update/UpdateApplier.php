<?php

namespace App\Update;

use App\Backups\BackupManager;
use App\Backups\BackupRestorer;
use App\Settings\AppSettings;
use Closure;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/**
 * Moteur d'application d'une mise à jour du Core : machine à états persistée dans `storage/app/updates/state.json`.
 *
 *   idle|done|failed|rolled_back --stage()--> staged --apply()--> swapped --finish()--> done
 *                                                          \--rollback()--> rolled_back (ou failed si incomplet)
 *
 * - `stage()` télécharge, contrôle et extrait la release dans `work/{version}/` sans toucher à l'application ;
 * - `apply()` sauvegarde, active la maintenance puis remplace les fichiers (les anciens vont dans `previous/{version}/`) ;
 * - `finish()` migre la base et rétablit la maintenance : à appeler depuis une NOUVELLE requête (ou un nouveau processus),
 *   qui exécute donc le code neuf ;
 * - `rollback()` remet les fichiers (et la base si les migrations ont commencé) d'avant.
 *
 * IMPORTANT (apply) : une fois le premier fichier remplacé, ce processus PHP exécute l'ANCIEN code en mémoire alors que le
 * disque porte déjà le NOUVEAU. L'étape de remplacement ne doit donc charger aucune classe qui ne l'est pas déjà : tout ce
 * dont elle a besoin (messages traduits, classes) est préparé avant, et elle n'utilise que des fonctions natives et des
 * classes déjà chargées. Elle se termine ensuite aussitôt ; les migrations tournent dans `finish()`.
 *
 * Chaque opération prend le verrou de mise à jour (flock non bloquant sur `updates/.lock`) : une seule à la fois. Les
 * chemins protégés (voir UpdatePaths) ne sont jamais écrits ni supprimés, et aucun écrit ne traverse un lien symbolique.
 * Aucun journal d'audit ici : l'action de l'écran (6b-3) qui appelle ces méthodes sait qui agit et peut le consigner.
 */
class UpdateApplier
{
    /** Nombre de fichiers entre deux sauvegardes de l'état pendant le remplacement. */
    private const BATCH = 200;

    private const TEMPORARY_PREFIX = '.kintai-new-';

    /** @var array<string, string> Gabarits traduits, préparés avant tout remplacement (voir la note de la classe). */
    private array $messages = [];

    /**
     * @param  Closure(string): (float|false)|null  $freeSpace  Espace libre d'un dossier (défaut : disk_free_space).
     * @param  Closure(string): bool|null  $writable  Test d'écriture d'un chemin (défaut : is_writable).
     */
    public function __construct(
        private readonly UpdatePaths $paths,
        private readonly ReleaseDownloader $downloader,
        private readonly BackupManager $backups,
        private readonly BackupRestorer $restorer,
        private readonly AppSettings $settings,
        private readonly MigrationRunner $migrations,
        private readonly ?Closure $freeSpace = null,
        private readonly ?Closure $writable = null,
    ) {}

    /** État courant, pour l'écran : phase, versions, erreurs. */
    public function state(): UpdateState
    {
        return UpdateState::load($this->paths->state());
    }

    /**
     * Télécharge, contrôle et extrait la release. N'écrit rien dans l'application.
     *
     * @return array{from: string, to: string, files: int, bytes: int}
     *
     * @throws UpdateException
     */
    public function stage(Release $release, bool $allowDowngrade = false): array
    {
        return $this->locked(function () use ($release, $allowDowngrade): array {
            $state = $this->state();

            if (! in_array($state->phase, [UpdateState::IDLE, UpdateState::DONE, UpdateState::FAILED, UpdateState::ROLLED_BACK], true)) {
                throw new UpdateException(__('An update is already in progress (:phase).', ['phase' => $state->phase]));
            }

            $current = Version::tryParse((string) config('kintai.version'))
                ?? throw new UpdateException(__('The current version of Kintai is unknown.'));

            if (! $allowDowngrade && ! $release->version->isNewerThan($current)) {
                throw new UpdateException(__('Version :to is not newer than the installed version (:from).', ['to' => (string) $release->version, 'from' => (string) $current]));
            }

            @set_time_limit(0);
            $this->discard();

            $directory = $this->paths->directory();
            $zip = $this->downloader->download($release, $directory);
            $to = (string) $release->version;
            $work = $this->paths->work($to);

            try {
                $info = ReleaseArchive::inspect($zip, $release->version);

                if ($info['min_php'] !== null && version_compare(PHP_VERSION, $info['min_php'], '<')) {
                    throw new UpdateException(__('This update requires PHP :required or later (this server runs :current).', ['required' => $info['min_php'], 'current' => PHP_VERSION]));
                }

                if ($info['min_from_version'] !== null && Version::parse($info['min_from_version'])->isNewerThan($current)) {
                    throw new UpdateException(__('This update can only be applied from version :min or later. Update to an intermediate version first.', ['min' => $info['min_from_version']]));
                }

                $free = $this->freeSpace === null ? @disk_free_space($directory) : ($this->freeSpace)($directory);

                // Espace libre inconnu (fonction désactivée par l'hébergeur) : on ne peut pas refuser.
                if ($free !== false && $free < 2 * $info['bytes']) {
                    throw new UpdateException(__('Not enough free disk space for this update (:needed needed).', ['needed' => $this->formatBytes(2 * $info['bytes'])]));
                }

                ReleaseArchive::extractTo($zip, $work);
                $files = $this->plan($work);
                $this->assertWritable($files);
            } catch (Throwable $e) {
                BackupManager::removeDirectory($work);

                throw $e;
            } finally {
                @unlink($zip);
            }

            $state->reset();
            $state->phase = UpdateState::STAGED;
            $state->from = (string) $current;
            $state->to = $to;
            $state->startedAt = gmdate('c');
            $state->save();

            return ['from' => (string) $current, 'to' => $to, 'files' => count($files), 'bytes' => $info['bytes']];
        });
    }

    /**
     * Abandonne une mise à jour préparée mais pas appliquée (phase `staged`) : supprime l'archive extraite.
     *
     * @throws UpdateException
     */
    public function cancel(): void
    {
        $this->locked(function (): void {
            $state = $this->state();

            if ($state->phase !== UpdateState::STAGED) {
                throw new UpdateException(__('There is no prepared update to cancel.'));
            }

            $this->discard();
            $state->reset();
            $state->save();
        });
    }

    /**
     * Sauvegarde, maintenance puis remplacement des fichiers. À la fin la phase est `swapped` : appeler `finish()` depuis une
     * nouvelle requête. Si quelque chose échoue pendant le remplacement, le retour arrière est fait avant de relancer l'erreur.
     *
     * @throws UpdateException
     */
    public function apply(): void
    {
        $this->locked(function (): void {
            @ignore_user_abort(true);
            @set_time_limit(0);

            $this->prepare();
            $state = $this->state();

            if ($state->phase !== UpdateState::STAGED || $state->to === null) {
                throw new UpdateException(__('There is no prepared update to apply.'));
            }

            $work = $this->paths->work($state->to);

            if (! is_dir($work)) {
                throw new UpdateException(__('The files of the prepared update are missing. Prepare the update again.'));
            }

            $files = $this->plan($work);
            $obsolete = $this->obsolete($files);

            // 1) Sauvegarde complète (base et fichiers téléversés) : c'est elle qui permettra de revenir en arrière.
            try {
                $backup = $this->backups->create(__('Automatic backup before updating to :version', ['version' => $state->to]), BackupManager::TRIGGER_UPDATE);
            } catch (Throwable $e) {
                throw new UpdateException(__('The safety backup could not be created, the update was not applied.').' '.$e->getMessage(), 0, $e);
            }

            // 2) Maintenance : l'état d'avant est mémorisé, et restauré à la fin quoi qu'il arrive entre-temps.
            $state->backupId = $backup->id;
            $state->maintenanceBefore = ['enabled' => $this->settings->maintenanceEnabled(), 'message' => $this->settings->maintenanceMessage()];
            $state->save();

            try {
                $this->settings->setMany([
                    'maintenance.enabled' => true,
                    'maintenance.message' => $state->maintenanceBefore['message'] !== '' ? $state->maintenanceBefore['message'] : __('Kintai is being updated. Please come back in a few minutes.'),
                ]);
            } catch (Throwable $e) {
                $this->restoreMaintenance($state);

                throw new UpdateException(__('The maintenance mode could not be enabled, the update was not applied.'), 0, $e);
            }

            // 3 à 6) Remplacement. La trace (phase swapped) est écrite AVANT le premier fichier.
            $state->phase = UpdateState::SWAPPED;
            $state->swapComplete = false;
            $state->save();

            try {
                $this->swap($state, $work, $files, $obsolete);
            } catch (Throwable $e) {
                $state->error = [$e->getMessage()];
                $this->rollbackNow($state);

                throw $e instanceof UpdateException ? $e : new UpdateException($this->messages['swap_failed'].' '.$e->getMessage(), 0, $e);
            }
        });
    }

    /**
     * Migrations et fin de la mise à jour (phase `swapped`, remplacement terminé). À exécuter avec le code neuf. Si les
     * migrations échouent, retour arrière complet (fichiers puis base) avant de relancer l'erreur.
     *
     * @return array{from: string, to: string, migrations_output: string}
     *
     * @throws UpdateException
     */
    public function finish(): array
    {
        return $this->locked(function (): array {
            @ignore_user_abort(true);
            @set_time_limit(0);

            $this->prepare();
            $state = $this->state();

            if ($state->phase !== UpdateState::SWAPPED) {
                throw new UpdateException(__('There is no update to finish.'));
            }

            if (! $state->swapComplete) {
                throw new UpdateException(__('The replacement of the files was interrupted. Roll the update back.'));
            }

            $state->migrationsStarted = true;
            $state->save();

            try {
                $output = $this->migrations->run();
            } catch (Throwable $e) {
                $state->error = [$e->getMessage()];
                $this->rollbackNow($state);

                throw new UpdateException(
                    ($state->phase === UpdateState::ROLLED_BACK ? __('The update failed and was rolled back.') : __('The update failed and could not be fully rolled back. See the update status.')).' '.$e->getMessage(),
                    0,
                    $e,
                );
            }

            $errors = [];

            try {
                $this->migrations->clearCaches();
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }

            try {
                $this->restoreMaintenance($state);
            } catch (Throwable $e) {
                report($e);
                $errors[] = __('The maintenance mode could not be restored: :error', ['error' => $e->getMessage()]);
            }

            $state->phase = UpdateState::DONE;
            $state->error = $errors;
            $state->save();
            $this->discard();

            return ['from' => (string) $state->from, 'to' => (string) $state->to, 'migrations_output' => $output];
        });
    }

    /**
     * Retour arrière d'une mise à jour inachevée (phase `swapped`, ou `failed` pour reprendre un retour arrière incomplet).
     * Idempotent : sans effet si la phase est déjà `rolled_back`, rejouable après un arrêt en plein retour arrière.
     *
     * @throws UpdateException
     */
    public function rollback(): void
    {
        $this->locked(function (): void {
            $this->prepare();
            $state = $this->state();

            if ($state->phase === UpdateState::ROLLED_BACK) {
                return;
            }

            if (! in_array($state->phase, [UpdateState::SWAPPED, UpdateState::FAILED], true)) {
                throw new UpdateException(__('There is no update to roll back.'));
            }

            $this->rollbackNow($state);
        });
    }

    // -- Verrou ------------------------------------------------------------------------------------------------

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     *
     * @throws UpdateException
     */
    private function locked(callable $callback): mixed
    {
        $directory = $this->paths->directory();

        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new UpdateException(__('The update folder could not be created.'));
        }

        $handle = @fopen($this->paths->lock(), 'c');

        if ($handle === false || ! flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }

            throw new UpdateException(__('Another update is already running.'));
        }

        try {
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    // -- Remplacement ------------------------------------------------------------------------------------------

    /**
     * Étapes 3 à 6 : n'utilise que des fonctions natives et des classes déjà chargées (voir la note de la classe).
     *
     * @param  list<string>  $files
     * @param  list<string>  $obsolete
     */
    private function swap(UpdateState $state, string $work, array $files, array $obsolete): void
    {
        $previous = $this->paths->previous((string) $state->to);

        foreach (array_chunk($files, self::BATCH) as $batch) {
            // Les chemins du lot sont notés AVANT d'être touchés : un arrêt brutal laisse une trace exacte.
            foreach ($batch as $relative) {
                $this->assertSafe($relative);
                $target = $this->paths->target($relative);

                if (is_dir($target)) {
                    throw $this->fail('not_a_file', $relative);
                }

                if (is_file($target)) {
                    $state->replaced[] = $relative;
                } else {
                    $state->created[] = $relative;
                }
            }

            $state->save();

            foreach ($batch as $relative) {
                $target = $this->paths->target($relative);

                if (is_file($target)) {
                    $this->copyFile($target, $previous.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative), $relative);
                }

                $this->ensureDirectory(dirname($relative));
                $this->placeFile($work.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative), $target, $relative);
            }
        }

        // Fichiers de la mise à jour précédente qui n'existent plus : l'ancienne copie est gardée pour le retour arrière.
        $state->deleted = $obsolete;
        $state->save();

        foreach ($obsolete as $relative) {
            $target = $this->paths->target($relative);
            $this->copyFile($target, $previous.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative), $relative);

            if (! @unlink($target)) {
                throw $this->fail('delete', $relative);
            }

            $this->pruneDirectories(dirname($relative));
        }

        if (is_file($this->paths->installed()) && ! @copy($this->paths->installed(), $this->paths->installedBackup())) {
            throw $this->fail('installed', 'installed-files.json');
        }

        $state->installedWritten = true;
        $state->save();
        $this->writeAtomically($this->paths->installed(), (string) json_encode($files, JSON_UNESCAPED_SLASHES));

        $this->clearBootstrapCache();

        $state->swapComplete = true;
        $state->save();
    }

    /**
     * Fichiers à poser : tout `work/`, sans les chemins protégés, `vendor/` d'abord, `bootstrap/`, `config/`, `artisan`,
     * `composer.json` et `public/index.php` en dernier (une interruption laisse ainsi un amorçage cohérent le plus longtemps possible).
     *
     * @return list<string>
     *
     * @throws UpdateException
     */
    private function plan(string $work): array
    {
        $base = rtrim($work, '/\\');
        $files = [];

        try {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
        } catch (Throwable $e) {
            throw new UpdateException(__('The files of the prepared update are missing. Prepare the update again.'), 0, $e);
        }

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($base) + 1));

            if (! $this->paths->isProtected($relative)) {
                $files[] = $relative;
            }
        }

        $rank = static function (string $path): int {
            if (str_starts_with($path, 'vendor/')) {
                return 0;
            }

            if (in_array($path, ['artisan', 'composer.json', 'public/index.php'], true) || str_starts_with($path, 'bootstrap/') || str_starts_with($path, 'config/')) {
                return 2;
            }

            return 1;
        };

        usort($files, static fn (string $a, string $b): int => [$rank($a), $a] <=> [$rank($b), $b]);

        return $files;
    }

    /**
     * Fichiers posés par la mise à jour précédente et absents de la nouvelle : existants, non protégés, sûrs.
     *
     * @param  list<string>  $files
     * @return list<string>
     */
    private function obsolete(array $files): array
    {
        $json = is_file($this->paths->installed()) ? @file_get_contents($this->paths->installed()) : false;
        $old = is_string($json) ? json_decode($json, true) : null;

        if (! is_array($old)) {
            return [];
        }

        $new = array_flip($files);
        $obsolete = [];

        foreach ($old as $relative) {
            if (! is_string($relative) || isset($new[$relative]) || ! $this->isValidPath($relative) || $this->paths->isProtected($relative)) {
                continue;
            }

            try {
                $this->assertSafe($relative);
            } catch (UpdateException) {
                continue;
            }

            if (is_file($this->paths->target($relative))) {
                $obsolete[] = $relative;
            }
        }

        return array_values(array_unique($obsolete));
    }

    // -- Retour arrière ----------------------------------------------------------------------------------------

    /** Retour arrière dans le verrou déjà pris. Ne lève jamais : les erreurs sont consignées dans l'état. */
    private function rollbackNow(UpdateState $state): void
    {
        $this->prepare();
        $errors = [];
        $previous = $this->paths->previous((string) $state->to);
        $from = static fn (string $directory, string $relative): string => $directory.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);

        // Fichiers remplacés et supprimés : on remet la copie gardée (absente = le fichier n'avait pas encore été touché).
        foreach ([...array_reverse($state->replaced), ...array_reverse($state->deleted)] as $relative) {
            try {
                if (! $this->isValidPath($relative) || $this->paths->isProtected($relative)) {
                    continue;
                }

                $copy = $from($previous, $relative);

                if (is_file($copy)) {
                    $this->assertSafe($relative);
                    $this->ensureDirectory(dirname($relative));
                    $this->placeFile($copy, $this->paths->target($relative), $relative);
                }
            } catch (Throwable $e) {
                $errors[] = $this->template('rollback_file', $relative).' '.$e->getMessage();
            }
        }

        // Fichiers créés : supprimés.
        foreach (array_reverse($state->created) as $relative) {
            try {
                if (! $this->isValidPath($relative) || $this->paths->isProtected($relative)) {
                    continue;
                }

                $this->assertSafe($relative);
                $target = $this->paths->target($relative);

                if (is_file($target) && ! @unlink($target)) {
                    throw $this->fail('delete', $relative);
                }

                $this->pruneDirectories(dirname($relative));
            } catch (Throwable $e) {
                $errors[] = $this->template('rollback_file', $relative).' '.$e->getMessage();
            }
        }

        try {
            if ($state->installedWritten) {
                if (is_file($this->paths->installedBackup())) {
                    if (! @copy($this->paths->installedBackup(), $this->paths->installed())) {
                        throw $this->fail('installed', 'installed-files.json');
                    }
                } elseif (is_file($this->paths->installed()) && ! @unlink($this->paths->installed())) {
                    throw $this->fail('installed', 'installed-files.json');
                }
            }

            $this->clearBootstrapCache();
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }

        // La base n'est restaurée que si les migrations ont commencé, et une seule fois.
        if ($state->migrationsStarted && ! $state->databaseRestored) {
            try {
                $info = $state->backupId === null ? null : $this->backups->find($state->backupId);

                if ($info === null) {
                    throw $this->fail('backup_missing', (string) $state->backupId);
                }

                $this->restorer->restore($info);
                $this->settings->flush();
                $state->databaseRestored = true;
            } catch (Throwable $e) {
                report($e);
                $errors[] = $this->template('rollback_database', '').' '.$e->getMessage();
            }
        }

        try {
            $this->restoreMaintenance($state);
        } catch (Throwable $e) {
            report($e);
            $errors[] = $this->template('rollback_maintenance', '').' '.$e->getMessage();
        }

        $state->error = array_values(array_unique([...$state->error, ...$errors]));
        $state->phase = $errors === [] ? UpdateState::ROLLED_BACK : UpdateState::FAILED;

        try {
            $state->save();
        } catch (Throwable $e) {
            report($e);
        }

        // Les copies d'origine restent en place si quelque chose n'a pas pu être remis (intervention à la main).
        if ($errors === []) {
            $this->discard();
        }
    }

    private function restoreMaintenance(UpdateState $state): void
    {
        if ($state->maintenanceBefore === null) {
            return;
        }

        $this->settings->setMany([
            'maintenance.enabled' => $state->maintenanceBefore['enabled'],
            'maintenance.message' => $state->maintenanceBefore['message'],
        ]);
    }

    // -- Fichiers ----------------------------------------------------------------------------------------------

    /**
     * Écrit `$source` à `$target` : copie dans un fichier temporaire voisin, droits conservés, puis rename atomique.
     *
     * @throws UpdateException
     */
    private function placeFile(string $source, string $target, string $relative): void
    {
        $temporary = dirname($target).DIRECTORY_SEPARATOR.self::TEMPORARY_PREFIX.bin2hex(random_bytes(4));
        $existing = is_file($target);
        $mode = $existing ? @fileperms($target) : @fileperms($source);

        if (! @copy($source, $temporary) || @filesize($temporary) !== @filesize($source)) {
            @unlink($temporary);

            throw $this->fail('write', $relative);
        }

        if ($mode !== false) {
            // Droits d'origine conservés (artisan reste exécutable) ; un fichier neuf garde ceux de l'archive.
            @chmod($temporary, $mode & 0777);
        }

        if (! $existing && $relative === 'artisan') {
            @chmod($temporary, 0755);
        }

        if (! @rename($temporary, $target)) {
            // Windows refuse parfois d'écraser un fichier : repli.
            @unlink($target);

            if (! @rename($temporary, $target)) {
                @unlink($temporary);

                throw $this->fail('write', $relative);
            }
        }
    }

    /**
     * Copie sûre (temporaire puis rename) d'un fichier de l'application vers un dossier de travail.
     *
     * @throws UpdateException
     */
    private function copyFile(string $source, string $destination, string $relative): void
    {
        $directory = dirname($destination);

        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw $this->fail('write', $relative);
        }

        $temporary = $destination.'.tmp-'.bin2hex(random_bytes(4));

        if (! @copy($source, $temporary) || @filesize($temporary) !== @filesize($source)) {
            @unlink($temporary);

            throw $this->fail('write', $relative);
        }

        $mode = @fileperms($source);

        if ($mode !== false) {
            @chmod($temporary, $mode & 0777);
        }

        if (! @rename($temporary, $destination)) {
            @unlink($destination);

            if (! @rename($temporary, $destination)) {
                @unlink($temporary);

                throw $this->fail('write', $relative);
            }
        }
    }

    private function writeAtomically(string $file, string $contents): void
    {
        $temporary = $file.'.tmp-'.bin2hex(random_bytes(4));

        if (@file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents)) {
            @unlink($temporary);

            throw $this->fail('installed', 'installed-files.json');
        }

        if (! @rename($temporary, $file)) {
            @unlink($file);

            if (! @rename($temporary, $file)) {
                @unlink($temporary);

                throw $this->fail('installed', 'installed-files.json');
            }
        }
    }

    /**
     * Crée les dossiers manquants d'un chemin relatif, en refusant de traverser un lien symbolique.
     *
     * @throws UpdateException
     */
    private function ensureDirectory(string $relative): void
    {
        if ($relative === '.' || $relative === '') {
            return;
        }

        $this->assertSafe($relative);
        $directory = $this->paths->target($relative);

        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw $this->fail('write', $relative);
        }
    }

    /** Retire les dossiers devenus vides (jamais protégés, jamais la racine) ; un dossier non vide est laissé tel quel. */
    private function pruneDirectories(string $relative): void
    {
        while ($relative !== '.' && $relative !== '' && ! $this->paths->isProtected($relative)) {
            $directory = $this->paths->target($relative);

            if (is_link($directory) || ! is_dir($directory) || ! @rmdir($directory)) {
                return;
            }

            $relative = dirname($relative);
        }
    }

    private function clearBootstrapCache(): void
    {
        $directory = $this->paths->target('bootstrap/cache');

        if (is_link($directory) || ! is_dir($directory)) {
            return;
        }

        foreach (glob($directory.DIRECTORY_SEPARATOR.'*.php') ?: [] as $file) {
            if (is_file($file) && ! is_link($file)) {
                @unlink($file);
            }
        }
    }

    /** Supprime les dossiers de travail (archive extraite, copies d'origine) : après réussite ou retour arrière complet. */
    private function discard(): void
    {
        BackupManager::removeDirectory($this->paths->directory().DIRECTORY_SEPARATOR.'work');
        BackupManager::removeDirectory($this->paths->directory().DIRECTORY_SEPARATOR.'previous');
        @unlink($this->paths->installedBackup());
    }

    // -- Contrôles ---------------------------------------------------------------------------------------------

    private function isValidPath(string $relative): bool
    {
        if ($relative === '' || str_contains($relative, "\0") || str_contains($relative, '\\') || str_starts_with($relative, '/') || preg_match('/^[A-Za-z]:/', $relative) === 1) {
            return false;
        }

        foreach (explode('/', $relative) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    /**
     * Refuse un chemin invalide, protégé, ou dont un maillon (dossier ou fichier) existant est un lien symbolique, et vérifie
     * que le dossier existant le plus proche reste sous la racine de l'application.
     *
     * @throws UpdateException
     */
    private function assertSafe(string $relative): void
    {
        if (! $this->isValidPath($relative) || $this->paths->isProtected($relative)) {
            throw $this->fail('unsafe', $relative);
        }

        $path = $this->paths->base();
        $existing = $path;

        foreach (explode('/', $relative) as $segment) {
            $path .= DIRECTORY_SEPARATOR.$segment;

            if (is_link($path)) {
                throw $this->fail('unsafe', $relative);
            }

            if (! file_exists($path)) {
                break;
            }

            $existing = $path;
        }

        $real = realpath($existing);
        $base = $this->paths->base();

        if ($real === false || ($real !== $base && ! str_starts_with($real, $base.DIRECTORY_SEPARATOR))) {
            throw $this->fail('unsafe', $relative);
        }
    }

    /**
     * Dossiers cibles inscriptibles et chemins sûrs, avant d'écrire quoi que ce soit.
     *
     * @param  list<string>  $files
     *
     * @throws UpdateException
     */
    private function assertWritable(array $files): void
    {
        $this->prepare();
        $problems = [];
        $checked = [];

        foreach ($files as $relative) {
            try {
                $this->assertSafe($relative);
            } catch (UpdateException) {
                $problems[$relative] = true;

                continue;
            }

            $target = $this->paths->target($relative);
            $directory = dirname($target);

            while (! is_dir($directory) && dirname($directory) !== $directory) {
                $directory = dirname($directory);
            }

            if (! isset($checked[$directory])) {
                $checked[$directory] = $this->isWritable($directory);
            }

            if (! $checked[$directory] || (is_file($target) && ! $this->isWritable($target))) {
                $problems[$checked[$directory] ? $relative : substr($directory, strlen($this->paths->base()) + 1)] = true;
            }
        }

        if ($problems !== []) {
            $list = array_keys($problems);
            $shown = implode(', ', array_slice($list, 0, 10)).(count($list) > 10 ? ', …' : '');

            throw new UpdateException(__('These paths are not writable or not safe: :paths', ['paths' => $shown]));
        }
    }

    private function isWritable(string $path): bool
    {
        return $this->writable === null ? is_writable($path) : (bool) ($this->writable)($path);
    }

    // -- Messages ----------------------------------------------------------------------------------------------

    /** Traduit d'avance les messages dont le remplacement a besoin (voir la note de la classe) et charge leurs classes. */
    private function prepare(): void
    {
        class_exists(UpdateException::class);

        if ($this->messages !== []) {
            return;
        }

        $this->messages = [
            'unsafe' => __('The path :path is protected or unsafe and was not touched.'),
            'not_a_file' => __('The path :path is a folder, a file was expected.'),
            'write' => __('The file :path could not be written.'),
            'delete' => __('The file :path could not be deleted.'),
            'installed' => __('The list of installed files (:path) could not be saved.'),
            'backup_missing' => __('The backup :path to restore was not found.'),
            'rollback_file' => __('The file :path could not be put back.'),
            'rollback_database' => __('The database could not be restored from the backup.'),
            'rollback_maintenance' => __('The maintenance mode could not be restored.'),
            'swap_failed' => __('The replacement of the files failed and was rolled back.'),
        ];
    }

    private function template(string $key, string $path): string
    {
        return str_replace(':path', $path, $this->messages[$key] ?? $key);
    }

    private function fail(string $key, string $path): UpdateException
    {
        return new UpdateException($this->template($key, $path));
    }

    private function formatBytes(int $bytes): string
    {
        return round($bytes / 1048576, 1).' '.__('MB');
    }
}
