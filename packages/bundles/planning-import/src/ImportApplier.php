<?php

namespace Kintai\Bundles\PlanningImport;

use App\Actions\Shifts\CreateShift;
use App\Actions\Shifts\DeleteShift;
use App\Actions\Shifts\ReplaceShift;
use App\Authorization\Authorizer;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Kintai\Bundles\PlanningImport\Detection\GridSettings;
use Kintai\Bundles\PlanningImport\Models\ImportAlias;
use Kintai\Contracts\Activity\ActivityRecorder;

/**
 * Confirmation de l'import : crée les shifts de l'aperçu avec les actions du Core (`CreateShift`, `ReplaceShift`,
 * `DeleteShift`), donc avec leurs droits et leurs règles ; rien n'est recopié ici. Tout se fait dans une transaction.
 *
 * - le même shift déjà planifié est ignoré ;
 * - un shift qui chevauche un shift existant de l'employé le remplace (l'ancien part à la corbeille, remplacé) ;
 * - un shift refusé par le Core (congé approuvé, saisie invalide) est ignoré et compté ;
 * - les avertissements du garde-fou (indisponibilité, chevauchement, durée) sont forcés : l'aperçu a été confirmé ;
 * - les shifts du magasin des employés importés, dans la période du fichier, à une date que le fichier ne couvre pas pour
 *   eux sont supprimés, si l'acteur a `shifts.delete` ;
 * - les noms rattachés deviennent des alias du magasin.
 */
class ImportApplier
{
    public function __construct(
        private readonly ImportAccess $access,
        private readonly Authorizer $authorizer,
        private readonly CreateShift $create,
        private readonly ReplaceShift $replace,
        private readonly DeleteShift $delete,
        private readonly ActivityRecorder $activity,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $rows  Lignes de l'aperçu (voir `ImportPlanner`), éventuellement corrigées.
     * @return array{created: int, replaced: int, skipped: int, removed: int}
     *
     * @throws AuthorizationException
     */
    public function apply(User $actor, int $storeId, array $rows): array
    {
        if (! $this->access->canImport($actor, $storeId)) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($actor, $storeId, $rows): array {
            $grid = GridSettings::forStore($storeId);
            $members = $this->access->members($storeId);
            $result = ['created' => 0, 'replaced' => 0, 'skipped' => 0, 'removed' => 0];

            $valid = [];
            $covered = [];
            foreach ($rows as $raw) {
                $row = $this->clean($raw, $members);
                if ($row === null) {
                    if (! ($raw['skip'] ?? false)) {
                        $result['skipped']++;
                        $this->forgetAlias($storeId, $raw['staff_name'] ?? null);
                    }

                    continue;
                }

                $covered[$row['user_id']][$row['date']] = true;
                $valid[] = $row;
            }

            foreach ($valid as $row) {
                if ($row['skip']) {
                    $result['skipped']++;

                    continue;
                }

                $outcome = $this->import($actor, $storeId, $row, $grid);
                $result[$outcome]++;
            }

            $result['removed'] = $this->removeObsolete($actor, $storeId, $covered);
            $this->saveAliases($storeId, $valid);

            $this->activity->record('planning_import.imported', null, $storeId, $result, $actor);

            return $result;
        });
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $members
     * @return array{date: string, staff_name: string, start_time: string, end_time: string, user_id: int, skip: bool}|null
     */
    private function clean(array $row, array $members): ?array
    {
        $userId = is_numeric($row['user_id'] ?? null) ? (int) $row['user_id'] : 0;
        $date = is_string($row['date'] ?? null) ? trim($row['date']) : '';
        $start = is_string($row['start_time'] ?? null) ? trim($row['start_time']) : '';
        $end = is_string($row['end_time'] ?? null) ? trim($row['end_time']) : '';

        if (! isset($members[$userId]) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1
            || preg_match('/^\d{2}:\d{2}$/', $start) !== 1 || preg_match('/^\d{2}:\d{2}$/', $end) !== 1) {
            return null;
        }

        return [
            'date' => $date,
            'staff_name' => is_string($row['staff_name'] ?? null) ? $row['staff_name'] : '',
            'start_time' => $start,
            'end_time' => $end,
            'user_id' => $userId,
            'skip' => (bool) ($row['skip'] ?? false),
        ];
    }

    /**
     * @param  array{date: string, staff_name: string, start_time: string, end_time: string, user_id: int, skip: bool}  $row
     * @return 'created'|'replaced'|'skipped'
     *
     * @throws AuthorizationException
     */
    private function import(User $actor, int $storeId, array $row, GridSettings $grid): string
    {
        try {
            $built = Shift::fromLocalTimes($row['date'], $row['start_time'], $row['end_time']);
            $existing = ImportPlanner::overlapping($storeId, $row['user_id'], $built);

            foreach ($existing as $shift) {
                if ($shift->starts_at->equalTo($built->starts_at) && $shift->ends_at->equalTo($built->ends_at)) {
                    return 'skipped';
                }
            }

            $input = [
                'store_id' => $storeId,
                'user_id' => $row['user_id'],
                'shift_date' => $row['date'],
                'start_time' => $row['start_time'],
                'end_time' => $row['end_time'],
                'pause_minutes' => $grid->pauseFor($built->durationMinutes),
            ];

            if ($existing->isEmpty()) {
                $this->create->handle($actor, $input, true);

                return 'created';
            }

            $first = $existing->first();
            $others = $existing->slice(1);
            $new = $this->replace->handle($actor, $first, $input, true, $others->pluck('id')->all());
            foreach ($others as $other) {
                $this->replace->handle($actor, $other, $new, true);
            }

            return 'replaced';
        } catch (ValidationException) {
            return 'skipped';
        }
    }

    /**
     * Supprime les shifts qui ne figurent plus dans le fichier : employés importés, magasin importé, entre la première
     * et la dernière date du fichier, à une date que le fichier ne couvre pas pour eux.
     *
     * @param  array<int, array<string, true>>  $covered  Dates couvertes par employé (lignes écartées comprises).
     */
    private function removeObsolete(User $actor, int $storeId, array $covered): int
    {
        if ($covered === [] || ! $this->authorizer->allows($actor, 'shifts.delete', $storeId)) {
            return 0;
        }

        $dates = array_merge(...array_map('array_keys', $covered));
        $min = min($dates);
        $max = max($dates);
        $removed = 0;

        foreach ($covered as $userId => $days) {
            $obsolete = Shift::query()
                ->where('store_id', $storeId)
                ->where('user_id', $userId)
                ->whereBetween('shift_date', [$min, $max])
                ->whereNull('replaced_by_id')
                ->get();

            foreach ($obsolete as $shift) {
                if (! isset($days[$shift->shift_date->toDateString()])) {
                    $this->delete->handle($actor, $shift);
                    $removed++;
                }
            }
        }

        return $removed;
    }

    private function forgetAlias(int $storeId, mixed $name): void
    {
        $name = is_string($name) ? mb_strtolower(trim($name)) : '';
        if ($name !== '') {
            ImportAlias::query()->where('store_id', $storeId)->where('staff_name', $name)->delete();
        }
    }

    /**
     * Retient le nom lu pour l'employé choisi (ou oublie l'alias si la ligne n'est rattachée à personne).
     *
     * @param  list<array{date: string, staff_name: string, start_time: string, end_time: string, user_id: int, skip: bool}>  $rows
     */
    private function saveAliases(int $storeId, array $rows): void
    {
        foreach ($rows as $row) {
            $name = mb_strtolower(trim($row['staff_name']));
            if ($row['skip'] || $name === '') {
                continue;
            }

            ImportAlias::query()->updateOrCreate(['store_id' => $storeId, 'staff_name' => $name], ['user_id' => $row['user_id']]);
        }
    }
}
