<?php

namespace Kintai\Bundles\PlanningImport;

use App\Models\Shift;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Kintai\Bundles\PlanningImport\Detection\GridSettings;
use Kintai\Bundles\PlanningImport\Detection\ShiftSheetParser;
use Kintai\Bundles\PlanningImport\Models\ImportAlias;

/**
 * Prépare l'aperçu : lit le fichier avec la grille du magasin, fusionne les plages contiguës d'un même nom, rattache
 * chaque nom à un employé du magasin (nom exact, sinon alias retenu) et signale les shifts déjà planifiés.
 *
 * Une ligne d'aperçu : date, staff_name, start_time, end_time, hours, duplicate_count, user_id (0 = inconnu), skip,
 * exists (même shift déjà planifié) et overlap (chevauche un autre shift de l'employé).
 */
class ImportPlanner
{
    public function __construct(private readonly ImportAccess $access) {}

    /**
     * @return list<array{date: string, staff_name: string, start_time: string, end_time: string, hours: float, duplicate_count: int, user_id: int, skip: bool, exists: bool, overlap: bool}>
     *
     * @throws ImportException
     */
    public function plan(int $storeId, string $path, string $originalName): array
    {
        $entries = (new ShiftSheetParser(GridSettings::forStore($storeId)))->parse($path, $originalName);
        $entries = $this->mergeAdjacent($entries);

        $members = $this->access->members($storeId);
        $aliases = $this->aliases($storeId, $members);
        $index = $this->nameIndex(array_keys($members));

        $rows = [];
        foreach ($entries as $entry) {
            $key = mb_strtolower(trim($entry['staff_name']));
            $matches = array_unique($index[$key] ?? []);
            $userId = count($matches) === 1 ? (int) reset($matches) : ($aliases[$key] ?? 0);

            [$exists, $overlap] = $userId > 0 ? $this->conflicts($storeId, $userId, $entry['date'], $entry['start_time'], $entry['end_time']) : [false, false];

            $rows[] = $entry + [
                'user_id' => $userId,
                'skip' => $userId === 0 || $exists,
                'exists' => $exists,
                'overlap' => $overlap,
            ];
        }

        return $rows;
    }

    /**
     * Les plages d'un même nom le même jour qui se touchent ou se chevauchent ne font qu'un shift.
     *
     * @param  list<array{date: string, staff_name: string, start_time: string, end_time: string, hours: float, duplicate_count: int}>  $entries
     * @return list<array{date: string, staff_name: string, start_time: string, end_time: string, hours: float, duplicate_count: int}>
     */
    private function mergeAdjacent(array $entries): array
    {
        $groups = [];
        foreach ($entries as $entry) {
            $groups[trim($entry['date']).'|'.mb_strtolower(trim($entry['staff_name']))][] = $entry;
        }

        $result = [];
        foreach ($groups as $group) {
            usort($group, fn (array $a, array $b): int => strcmp($a['start_time'], $b['start_time']));
            $merged = $group[0];

            foreach (array_slice($group, 1) as $current) {
                if ($current['start_time'] > $merged['end_time']) {
                    $result[] = $merged;
                    $merged = $current;

                    continue;
                }

                $overlapping = $current['start_time'] < $merged['end_time'];
                $merged['hours'] = $overlapping ? max($merged['hours'], $current['hours']) : round($merged['hours'] + $current['hours'], 1);
                $merged['end_time'] = max($merged['end_time'], $current['end_time']);
            }

            $result[] = $merged;
        }

        return $result;
    }

    /**
     * Alias valides du magasin (nom en minuscules => employé). Un alias dont l'employé n'est plus membre actif est
     * supprimé ; un nom qui désignerait plusieurs employés est ignoré.
     *
     * @param  array<int, string>  $members
     * @return array<string, int>
     */
    private function aliases(int $storeId, array $members): array
    {
        $valid = [];
        $ambiguous = [];

        foreach (ImportAlias::query()->where('store_id', $storeId)->orderBy('id')->get() as $alias) {
            $name = mb_strtolower(trim($alias->staff_name));

            if ($name === '' || ! isset($members[$alias->user_id])) {
                $alias->delete();

                continue;
            }

            if (isset($ambiguous[$name])) {
                continue;
            }

            if (isset($valid[$name])) {
                unset($valid[$name]);
                $ambiguous[$name] = true;

                continue;
            }

            $valid[$name] = $alias->user_id;
        }

        return $valid;
    }

    /**
     * Nom complet (« nom prénom ») et nom affiché des employés, en minuscules => identifiants.
     *
     * @param  list<int>  $userIds
     * @return array<string, list<int>>
     */
    private function nameIndex(array $userIds): array
    {
        $index = [];

        foreach (User::query()->whereIn('id', $userIds)->get(['id', 'name', 'first_name', 'last_name']) as $user) {
            foreach ([trim($user->last_name.' '.$user->first_name), trim($user->name)] as $name) {
                if ($name !== '') {
                    $index[mb_strtolower($name)][] = $user->id;
                }
            }
        }

        return $index;
    }

    /**
     * @return array{bool, bool} Même shift déjà planifié ; chevauchement avec un autre shift de l'employé.
     */
    private function conflicts(int $storeId, int $userId, string $date, string $start, string $end): array
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return [false, false];
        }

        $built = Shift::fromLocalTimes($date, $start, $end);
        $exists = false;
        $overlap = false;

        foreach (self::overlapping($storeId, $userId, $built) as $shift) {
            if ($shift->starts_at->equalTo($built->starts_at) && $shift->ends_at->equalTo($built->ends_at)) {
                $exists = true;
            } else {
                $overlap = true;
            }
        }

        return [$exists, $overlap];
    }

    /**
     * Shifts actifs de l'employé dans le magasin qui chevauchent le créneau.
     *
     * @return Collection<int, Shift>
     */
    public static function overlapping(int $storeId, int $userId, Shift $built)
    {
        return Shift::query()
            ->where('store_id', $storeId)
            ->where('user_id', $userId)
            ->whereNull('replaced_by_id')
            ->where('starts_at', '<', $built->ends_at)
            ->where('ends_at', '>', $built->starts_at)
            ->orderBy('starts_at')
            ->get();
    }
}
