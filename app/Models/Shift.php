<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\ShiftChangeNotifier;
use Illuminate\Database\Eloquent\Model;

final class Shift extends Model
{
    protected $table = 'shifts';
    protected $guarded = [];
    public $timestamps = false;

    public static function findById(int $id): ?array
    {
        $shift = static::find($id);
        return $shift ? $shift->toArray() : null;
    }

    public static function findByStore(int $storeId): array
    {
        return static::where('store_id', $storeId)->get()->toArray();
    }

    public static function findByStoreBetween(int $storeId, string $from, string $to): array
    {
        // Index (store_id, shift_date, deleted_at) : seule la plage demandée est lue, pas tout l'historique.
        return static::where('store_id', $storeId)
            ->whereBetween('shift_date', [$from, $to])
            // Ordre explicite = celui que findByStore() obtenait implicitement sous SQLite (index store_id, user_id,
            // shift_date) : les statistiques construisent des tableaux indexés par employé dont l'ordre s'affiche.
            // Explicite, il est aussi le même sous MySQL.
            ->orderBy('user_id')
            ->orderBy('shift_date')
            ->orderBy('id')
            // Lecture brute, sans instancier un modèle par ligne : le modèle Shift n'a ni cast, ni accesseur, ni champ
            // masqué, son toArray() renvoie exactement les colonnes — même résultat, pour une fraction du coût.
            ->toBase()
            ->get()
            ->map(static fn($row): array => (array) $row)
            ->all();
    }

    public static function findByUser(int $userId): array
    {
        return static::where('user_id', $userId)->get()->toArray();
    }

    public static function findByDate(int $storeId, string $date): array
    {
        return static::where('store_id', $storeId)
            ->where('shift_date', $date)
            ->get()
            ->toArray();
    }

    public static function findByUserAndDate(int $userId, int $storeId, string $date): array
    {
        return static::where('user_id', $userId)
            ->where('store_id', $storeId)
            ->where('shift_date', $date)
            ->get()
            ->toArray();
    }

    public static function findAll(): array
    {
        return static::all()->toArray();
    }

    public static function findBetween(?array $storeIds, string $from, string $to): array
    {
        if ($storeIds === []) {
            return [];
        }
        $query = static::whereBetween('shift_date', [$from, $to]);
        if ($storeIds !== null) {
            $query->whereIn('store_id', array_map('intval', $storeIds));
        }
        // Lecture brute : le modèle Shift n'a ni cast ni accesseur (voir findByStoreBetween()).
        return $query->orderBy('shift_date')
            ->orderBy('id')
            ->toBase()
            ->get()
            ->map(static fn($row): array => (array) $row)
            ->all();
    }

    public static function findAllByDate(string $date): array
    {
        return static::where('shift_date', $date)->get()->toArray();
    }

    public static function findOpen(?int $storeId = null): array
    {
        $query = static::where('is_open', 1);
        if ($storeId !== null) {
            $query->where('store_id', $storeId);
        }
        return $query->get()->toArray();
    }

    public static function put(array $data): array
    {
        $before = null;
        if (!empty($data['id'])) {
            $shift = static::findOrFail((int) $data['id']);
            $before = $shift->toArray();
            $data['ical_sequence'] = ((int) ($shift->ical_sequence ?? 0)) + 1;
            if (!array_key_exists('updated_at', $data)) {
                $data['updated_at'] = date('Y-m-d H:i:s');
            }
            $shift->fill($data);
            $shift->save();
        } else {
            $data['ical_sequence'] = 0;
            $shift = static::create($data);
        }
        // Annonce l'écriture (événements pour les bundles, synchronisation des agendas externes), quel que soit l'appelant.
        ShiftChangeNotifier::current()->saved($shift->toArray(), $before);
        return $shift->toArray();
    }

    public static function remove(int $id): int
    {
        $shift = static::find($id);
        if (!$shift) {
            return 0;
        }

        // Trace la suppression avant le hard delete, pour que le flux iCal de
        // l'employé puisse émettre un VEVENT CANCELLED (voir IcalController::feed()).
        ShiftDeletionLog::create([
            'shift_id'         => $shift->id,
            'store_id'         => $shift->store_id,
            'user_id'          => $shift->user_id,
            'shift_date'       => $shift->shift_date,
            'start_time'       => $shift->start_time,
            'end_time'         => $shift->end_time,
            'cross_midnight'   => $shift->cross_midnight ?? 0,
            'shift_type_id'    => $shift->shift_type_id,
            'pause_minutes'    => $shift->pause_minutes ?? 0,
            'ical_sequence'    => ((int) ($shift->ical_sequence ?? 0)) + 1,
            'shift_created_at' => $shift->created_at,
            'deleted_at'       => date('Y-m-d H:i:s'),
        ]);

        $row = $shift->toArray();
        $deleted = $shift->delete() ? 1 : 0;
        if ($deleted === 1) {
            ShiftChangeNotifier::current()->deleted($row);
        }
        return $deleted;
    }

    public static function findRecentDeletionsByUserAndStore(int $userId, int $storeId, string $since): array
    {
        return ShiftDeletionLog::where('user_id', $userId)
            ->where('store_id', $storeId)
            ->where('deleted_at', '>=', $since)
            ->get()
            ->map(fn (ShiftDeletionLog $log) => [
                'id'             => $log->shift_id,
                'shift_type_id'  => $log->shift_type_id,
                'shift_date'     => $log->shift_date,
                'start_time'     => $log->start_time,
                'end_time'       => $log->end_time,
                'cross_midnight' => $log->cross_midnight,
                'pause_minutes'  => $log->pause_minutes,
                'ical_sequence'  => $log->ical_sequence,
                'created_at'     => $log->shift_created_at,
                'updated_at'     => $log->deleted_at,
                'deleted_at'     => $log->deleted_at,
            ])
            ->all();
    }

    public static function closeOpenShiftTo(int $id, int $userId): ?array
    {
        $shift = static::find($id);
        if ($shift === null) {
            return null;
        }
        $affected = static::where('id', $id)
            ->where('is_open', 1)
            ->update([
                'user_id'       => $userId,
                'is_open'       => 0,
                'ical_sequence' => ((int) $shift->ical_sequence) + 1,
                'updated_at'    => date('Y-m-d H:i:s'),
            ]);
        if ($affected === 0) {
            return null;
        }
        $updated = static::find($id)->toArray();
        ShiftChangeNotifier::current()->saved($updated, $shift->toArray());
        return $updated;
    }
}
