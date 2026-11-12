<?php

namespace App\Actions\Backups;

use App\Activity\ActivityLogger;
use App\Backups\BackupException;
use App\Backups\BackupLock;
use App\Backups\BackupManager;
use App\Backups\DataResetPlanner;
use App\Backups\PasswordConfirmation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Réinitialisation des données (Owner seulement) : efface les données de planning et de l'organisation en gardant les
 * comptes, les rôles, les réglages et le journal. La « réinitialisation d'usine » (retour à l'assistant d'installation)
 * viendra avec l'assistant, en phase 6.
 *
 * Deux garde-fous : le mot de passe de l'Owner et la phrase `RESET` à recopier. Une sauvegarde automatique est faite juste
 * avant ; si elle échoue, rien n'est effacé. La réinitialisation est journalisée (`app.data_reset`) : le journal est préservé.
 */
class ResetApplicationData
{
    public function __construct(
        private readonly DataResetPlanner $planner,
        private readonly BackupManager $manager,
        private readonly PasswordConfirmation $passwords,
        private readonly ActivityLogger $logger,
    ) {}

    /**
     * @param  list<string>  $keep  Catégories gardées : `stores`, `shift_types`, `ui_prefs`.
     * @return array{backup: string, tables: array<string, int>} Sauvegarde préalable ; tables vidées et lignes supprimées.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws BackupException
     */
    public function handle(User $actor, array $keep, string $confirmation, string $password): array
    {
        if (! Gate::forUser($actor)->allows('manage-settings')) {
            throw new AuthorizationException;
        }

        if ($confirmation !== DataResetPlanner::CONFIRMATION) {
            throw ValidationException::withMessages(['confirmation' => __('Type :text to confirm.', ['text' => DataResetPlanner::CONFIRMATION])]);
        }

        $this->passwords->verify($actor, $password);

        $keep = array_values(array_intersect(array_keys(DataResetPlanner::OPTIONAL), $keep));

        return BackupLock::run($this->manager->directory(), function () use ($actor, $keep): array {
            $backup = $this->manager->create(__('Automatic backup before the data reset'), BackupManager::TRIGGER_RESET);

            $tables = $this->planner->tablesToWipe($keep);
            $counts = $this->planner->counts($tables);

            $this->planner->wipe($tables);

            $this->logger->record('app.data_reset', null, null, [
                'kept' => $keep,
                'tables' => array_keys(array_filter($counts)),
                'rows' => array_sum($counts),
                'backup' => $backup->id,
            ], $actor);

            return ['backup' => $backup->id, 'tables' => $counts];
        });
    }
}
