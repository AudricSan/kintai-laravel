<?php

namespace App\Actions\Shifts;

use App\Authorization\Authorizer;
use App\Exceptions\ShiftWarningsException;
use App\Models\Shift;
use App\Models\Store;
use App\Models\StoreMembership;
use App\Models\User;
use App\Planning\ShiftGuard;
use App\Planning\ShiftWageCalculator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Socle commun de CreateShift, UpdateShift, MoveShift et ReplaceShift : valide la saisie, contrôle les droits,
 * calcule le type dominant et applique les garde-fous. Renvoie le shift prêt à enregistrer (non sauvegardé).
 *
 * Saisie : store_id, user_id (null = ouvert), shift_date (Y-m-d), start_time et end_time ("H:i", heures locales
 * de l'instance), pause_minutes, notes, hourly_rate_override, net_minutes_override. À la modification, une clé
 * absente garde la valeur actuelle.
 */
class ShiftPreparer
{
    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly ShiftGuard $guard,
        private readonly ShiftWageCalculator $calculator,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @param  list<int>  $ignoreShiftIds  Shifts qui vont être remplacés : ils ne comptent pas dans les chevauchements.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws ShiftWarningsException
     */
    public function prepare(User $actor, array $input, ?Shift $existing, bool $force, array $ignoreShiftIds = []): Shift
    {
        if ($existing !== null && $existing->trashed()) {
            throw ValidationException::withMessages(['shift' => __('This shift is deleted.')]);
        }

        $input = $this->normalize($input, $existing);

        $store = Store::query()->find(is_numeric($input['store_id'] ?? null) ? (int) $input['store_id'] : 0);
        if ($store === null) {
            throw ValidationException::withMessages(['store_id' => __('The selected store does not exist.')]);
        }

        $this->authorizeStores($actor, $existing, $store);

        $data = Validator::make($input, [
            'user_id' => ['nullable', 'integer'],
            'shift_date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'date_format:H:i'],
            // Début = fin : ni shift de 0 h ni de 24 h.
            'end_time' => ['required', 'date_format:H:i', 'different:start_time'],
            'pause_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'hourly_rate_override' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'net_minutes_override' => ['nullable', 'integer', 'min:0', 'max:1500'],
        ])->validate();

        $target = $existing ?? new Shift(['created_by' => $actor->id]);
        $built = Shift::fromLocalTimes($data['shift_date'], $data['start_time'], $data['end_time']);

        $target->fill([
            'store_id' => $store->id,
            'user_id' => $data['user_id'],
            'shift_date' => $data['shift_date'],
            'starts_at' => $built->starts_at,
            'ends_at' => $built->ends_at,
            'pause_minutes' => (int) $data['pause_minutes'],
            'notes' => $data['notes'],
            'hourly_rate_override' => $data['hourly_rate_override'],
            'net_minutes_override' => $data['net_minutes_override'],
            'shift_type_id' => $this->calculator->dominantTypeId($store, $data['start_time'], $data['end_time']),
        ]);
        $target->setRelation('store', $store);
        $target->unsetRelation('user');

        $this->authorizeOverrides($actor, $target, $store);

        $placementChanged = $target->isDirty(['store_id', 'user_id']);
        if ($target->user_id !== null && ($existing === null || $placementChanged)) {
            $this->assertActiveMember($target->user_id, $store);
        }

        $scheduleChanged = $existing === null || $target->isDirty(['store_id', 'user_id', 'shift_date', 'starts_at', 'ends_at']);
        $moved = $existing === null || $target->isDirty(['store_id', 'user_id', 'shift_date']);

        $issues = $this->guard->check($target, $scheduleChanged, $moved, $ignoreShiftIds);

        $blocking = array_values(array_filter($issues, fn ($issue): bool => $issue->isBlocking()));
        if ($blocking !== []) {
            throw ValidationException::withMessages(['user_id' => array_map(fn ($issue): string => $issue->message, $blocking)]);
        }

        if ($issues !== [] && ! $force) {
            throw new ShiftWarningsException($issues);
        }

        return $target;
    }

    /**
     * Complète la saisie avec le shift existant et ramène les champs vides à null.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function normalize(array $input, ?Shift $existing): array
    {
        if ($existing !== null) {
            $input += [
                'store_id' => $existing->store_id,
                'user_id' => $existing->user_id,
                'shift_date' => $existing->shift_date->toDateString(),
                'start_time' => $existing->localStartsAt->format('H:i'),
                'end_time' => $existing->localEndsAt->format('H:i'),
                'pause_minutes' => $existing->pause_minutes,
                'notes' => $existing->notes,
                'hourly_rate_override' => $existing->hourly_rate_override,
                'net_minutes_override' => $existing->net_minutes_override,
            ];
        }

        foreach ($input as $key => $value) {
            if (is_string($value)) {
                $input[$key] = trim($value);
            }
        }

        foreach (['user_id', 'notes', 'hourly_rate_override', 'net_minutes_override'] as $nullable) {
            if (($input[$nullable] ?? '') === '') {
                $input[$nullable] = null;
            }
        }

        if (($input['pause_minutes'] ?? '') === '') {
            $input['pause_minutes'] = 0;
        }

        return $input;
    }

    /**
     * Création : `shifts.create` sur le magasin. Modification : `shifts.update` sur le magasin du shift
     * et sur le nouveau magasin s'il change.
     *
     * @throws AuthorizationException
     */
    private function authorizeStores(User $actor, ?Shift $existing, Store $store): void
    {
        if ($existing === null) {
            $this->authorize($actor, 'shifts.create', $store->id);

            return;
        }

        $this->authorize($actor, 'shifts.update', $existing->store_id);
        if ($store->id !== $existing->store_id) {
            $this->authorize($actor, 'shifts.update', $store->id);
        }
    }

    /**
     * Taux horaire et minutes nettes imposés : réservés à `payroll.generate` (ou Owner) quand ils changent.
     *
     * @throws AuthorizationException
     */
    private function authorizeOverrides(User $actor, Shift $shift, Store $store): void
    {
        $changed = $shift->exists
            ? $shift->isDirty(['hourly_rate_override', 'net_minutes_override'])
            : ($shift->hourly_rate_override !== null || $shift->net_minutes_override !== null);

        if ($changed) {
            $this->authorize($actor, 'payroll.generate', $store->id);
        }
    }

    /**
     * L'employé ciblé doit être membre actif du magasin du shift (donc visible de l'acteur qui y a des droits).
     *
     * @throws ValidationException
     */
    private function assertActiveMember(int $userId, Store $store): void
    {
        $member = User::query()->whereKey($userId)->exists()
            && StoreMembership::query()->where('store_id', $store->id)->where('user_id', $userId)->where('is_active', true)->exists();

        if (! $member) {
            throw ValidationException::withMessages(['user_id' => __('The employee is not an active member of this store.')]);
        }
    }

    /**
     * @throws AuthorizationException
     */
    private function authorize(User $actor, string $permission, int $storeId): void
    {
        if (! $this->authorizer->allows($actor, $permission, $storeId)) {
            throw new AuthorizationException;
        }
    }
}
