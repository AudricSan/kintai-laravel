<?php

namespace Kintai\Bundles\Timeclock;

use App\Models\Shift;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\ValidationException;
use Kintai\Bundles\Timeclock\Models\Timeclock;
use Kintai\Contracts\Activity\ActivityRecorder;
use Kintai\Contracts\Api\ApiException;

/**
 * Écritures des pointages, communes aux écrans et à l'API : droits (`TimeclockAccess`) et journal.
 *
 * Un pointage fait hors ligne transmet l'heure réelle du geste (`$clientTime`) plutôt que celle de la synchronisation,
 * ce qui compte pour la paie ; elle n'est crue que dans une fenêtre raisonnable (24 h dans le passé, 2 min dans le
 * futur pour la dérive d'horloge), sinon l'heure du serveur est utilisée.
 *
 * Erreurs : `ApiException` 409 `already_clocked_in`, 404 `not_clocked_in` ; `ValidationException` pour un magasin
 * invalide ou des heures incohérentes ; `AuthorizationException` pour un refus.
 */
class TimeclockActions
{
    public function __construct(
        private readonly TimeclockAccess $access,
        private readonly ActivityRecorder $activity,
    ) {}

    /**
     * Le pointage en cours de l'employé, s'il y en a un.
     */
    public function active(User $user): ?Timeclock
    {
        return Timeclock::query()->where('user_id', $user->id)->whereNull('clock_out_at')->latest('clock_in_at')->first();
    }

    /**
     * @throws ApiException
     * @throws ValidationException
     */
    public function clockIn(User $employee, ?int $storeId, ?string $clientTime = null, ?string $ip = null): Timeclock
    {
        if ($this->active($employee) !== null) {
            throw new ApiException(409, 'already_clocked_in', __('A clock-in is already running: clock out before starting a new one.'));
        }

        $stores = $this->access->memberStores($employee);

        if ($stores === []) {
            throw ValidationException::withMessages(['store_id' => __('You are not a member of any store where the time clock is available.')]);
        }

        // Sans choix (ancien client, file hors ligne) : le premier magasin de l'employé.
        $storeId ??= array_key_first($stores);

        if (! isset($stores[$storeId])) {
            throw ValidationException::withMessages(['store_id' => __('You are not a member of the selected store.')]);
        }

        $now = Date::now();
        $at = $this->effective($clientTime, $now);
        $day = $at->copy()->timezone(Timeclock::timezone())->toDateString();

        $entry = Timeclock::create([
            'user_id' => $employee->id,
            'store_id' => $storeId,
            'shift_id' => $this->shiftFor($employee, $storeId, $day, $at)?->id,
            'shift_date' => $day,
            'clock_in_at' => $at,
            'clock_in_ip' => $ip,
        ]);

        $this->activity->record('timeclock.clock_in', $entry, $storeId, $this->offline($at, $now), $employee);

        return $entry;
    }

    /**
     * @throws ApiException
     */
    public function clockOut(User $employee, ?string $clientTime = null, ?string $ip = null): Timeclock
    {
        $entry = $this->active($employee)
            ?? throw new ApiException(404, 'not_clocked_in', __('There is no running clock-in to close.'));

        $now = Date::now();
        $at = $this->effective($clientTime, $now);

        // Deux gestes mis en file hors ligne et rejoués dans le désordre ne doivent pas donner une durée négative.
        if ($at->lessThan($entry->clock_in_at)) {
            $at = $now;
        }

        $entry->clock_out_at = $at;
        $entry->clock_out_ip = $ip;
        $entry->duration_minutes = $this->minutes($entry->clock_in_at, $at);
        $entry->save();

        $this->activity->record('timeclock.clock_out', $entry, $entry->store_id, $this->offline($at, $now) + ['minutes' => $entry->duration_minutes], $employee);

        return $entry;
    }

    /**
     * Corrige les heures (UTC) et la note d'un pointage ; la sortie vide remet le pointage « en cours ».
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function update(User $actor, Timeclock $entry, CarbonInterface $in, ?CarbonInterface $out, ?string $notes): Timeclock
    {
        if (! $this->access->canUpdate($actor, $entry)) {
            throw new AuthorizationException;
        }

        if ($out !== null && $out->lessThan($in)) {
            throw ValidationException::withMessages(['clock_out_at' => __('The clock-out cannot be before the clock-in.')]);
        }

        if ($out === null && Timeclock::query()->where('user_id', $entry->user_id)->whereNull('clock_out_at')->where('id', '!=', $entry->id)->exists()) {
            throw ValidationException::withMessages(['clock_out_at' => __('This employee already has a running clock-in.')]);
        }

        $entry->clock_in_at = $in;
        $entry->shift_date = $in->copy()->timezone(Timeclock::timezone())->startOfDay();
        $entry->clock_out_at = $out;
        $entry->duration_minutes = $out === null ? null : $this->minutes($in, $out);
        $entry->notes = $notes === null || trim($notes) === '' ? null : trim($notes);
        $changed = array_keys($entry->getDirty());
        $entry->save();

        if ($changed !== []) {
            $this->activity->record('timeclock.edited', $entry, $entry->store_id, ['fields' => $changed], $actor);
        }

        return $entry;
    }

    /**
     * @throws AuthorizationException
     */
    public function delete(User $actor, Timeclock $entry): void
    {
        if (! $this->access->canDelete($actor, $entry)) {
            throw new AuthorizationException;
        }

        $entry->delete();
        $this->activity->record('timeclock.deleted', $entry, $entry->store_id, ['employee_id' => $entry->user_id, 'date' => $entry->shift_date->toDateString()], $actor);
    }

    private function minutes(CarbonInterface $in, CarbonInterface $out): int
    {
        return max(0, (int) round($in->diffInSeconds($out) / 60));
    }

    /**
     * Heure retenue : celle du client si elle est plausible, sinon celle du serveur.
     */
    private function effective(?string $clientTime, CarbonInterface $now): CarbonInterface
    {
        if ($clientTime === null || $clientTime === '') {
            return $now;
        }

        try {
            $parsed = Date::parse($clientTime)->utc();
        } catch (\Throwable) {
            return $now;
        }

        $ago = $now->getTimestamp() - $parsed->getTimestamp();

        return ($ago < -120 || $ago > 86400) ? $now : $parsed;
    }

    /**
     * @return array<string, mixed>
     */
    private function offline(CarbonInterface $effective, CarbonInterface $now): array
    {
        return $effective->equalTo($now) ? [] : ['offline_sync' => true, 'client_time' => $effective->toIso8601String(), 'synced_at' => $now->toIso8601String()];
    }

    /**
     * Le shift de l'employé dans ce magasin ce jour-là : celui dont le début est le plus proche de l'entrée.
     */
    private function shiftFor(User $employee, int $storeId, string $day, CarbonInterface $at): ?Shift
    {
        return Shift::query()
            ->where('user_id', $employee->id)
            ->where('store_id', $storeId)
            ->where('shift_date', $day)
            ->whereNull('replaced_by_id')
            ->get()
            ->sortBy(fn (Shift $shift): int => (int) abs($shift->starts_at->diffInSeconds($at, false)))
            ->first();
    }
}
