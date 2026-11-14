<?php

namespace App\Actions\Availability;

use App\Models\Availability;
use App\Models\Store;
use App\Models\StoreMembership;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Enregistre les disponibilités d'un employé pour un magasin : sept jours ISO (1 = lundi ... 7 = dimanche) qui
 * remplacent les lignes existantes. Information seulement : le planning prévient, il ne bloque pas.
 * Un employé ne modifie que les siennes, pour un magasin dont il est membre.
 */
class SaveAvailability
{
    /**
     * @param  array<int|string, array{available?: mixed, start?: string|null, end?: string|null}>  $days  Par jour ISO.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, User $employee, Store $store, array $days): void
    {
        if ($actor->id !== $employee->id
            || ! StoreMembership::query()->where('store_id', $store->id)->where('user_id', $employee->id)->exists()) {
            throw new AuthorizationException;
        }

        $rules = [];
        $rows = [];
        foreach (range(1, 7) as $day) {
            $entry = $days[$day] ?? [];
            $rows[$day] = [
                'available' => filter_var($entry['available'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'start' => ($entry['start'] ?? '') === '' ? null : $entry['start'],
                'end' => ($entry['end'] ?? '') === '' ? null : $entry['end'],
            ];
            $rules["{$day}.start"] = ['nullable', 'date_format:H:i', "required_with:{$day}.end"];
            $rules["{$day}.end"] = ['nullable', 'date_format:H:i', "required_with:{$day}.start", "different:{$day}.start"];
        }

        Validator::make($rows, $rules)->validate();

        DB::transaction(function () use ($employee, $store, $rows): void {
            Availability::query()->where('user_id', $employee->id)->where('store_id', $store->id)->delete();

            foreach ($rows as $day => $row) {
                // Une plage n'a de sens que pour un jour disponible.
                Availability::query()->create([
                    'user_id' => $employee->id,
                    'store_id' => $store->id,
                    'day_of_week' => $day,
                    'is_available' => $row['available'],
                    'starts_at' => $row['available'] ? $row['start'] : null,
                    'ends_at' => $row['available'] ? $row['end'] : null,
                ]);
            }
        });
    }
}
