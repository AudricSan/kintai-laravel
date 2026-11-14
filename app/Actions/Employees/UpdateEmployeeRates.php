<?php

namespace App\Actions\Employees;

use App\Authorization\Authorizer;
use App\Models\ShiftType;
use App\Models\StoreMembership;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Kintai\Contracts\Events\EmployeeRatesUpdated;

/**
 * Taux horaires personnels d'un employé, par type de shift.
 */
class UpdateEmployeeRates
{
    use AuthorizesEmployeeActions;

    public function __construct(private readonly Authorizer $authorizer) {}

    /**
     * Types actifs rattachés à au moins un magasin commun à l'employé et à l'acteur.
     *
     * @return Collection<int, ShiftType>
     */
    public function eligibleShiftTypes(User $actor, User $target): Collection
    {
        $common = StoreMembership::query()->where('user_id', $target->id)->pluck('store_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $allowed = $this->authorizer->storeIdsFor($actor, 'employees.update');

        if ($allowed !== null) {
            $common = array_values(array_intersect($common, $allowed));
        }

        return ShiftType::query()
            ->where('is_active', true)
            ->whereHas('stores', fn ($query) => $query->whereIn('stores.id', $common))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  array<array-key, mixed>  $rates  identifiant du type de shift => taux (vide = supprimer le taux personnel)
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, User $target, array $rates): void
    {
        $this->authorizeOnEmployee($actor, 'employees.update', $target);

        $eligible = $this->eligibleShiftTypes($actor, $target)->modelKeys();

        foreach ($rates as $shiftTypeId => $rate) {
            if (! in_array((int) $shiftTypeId, $eligible, true)) {
                throw ValidationException::withMessages(['rates.'.$shiftTypeId => __('This shift type is not available for this employee.')]);
            }

            $rates[$shiftTypeId] = is_string($rate) && trim($rate) === '' ? null : $rate;
        }

        Validator::make(['rates' => $rates], [
            'rates' => ['array'],
            'rates.*' => ['nullable', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
        ])->validate();

        DB::transaction(function () use ($target, $rates): void {
            foreach ($rates as $shiftTypeId => $rate) {
                if ($rate === null) {
                    $target->personalRates()->detach((int) $shiftTypeId);
                } else {
                    $target->personalRates()->syncWithoutDetaching([
                        (int) $shiftTypeId => ['hourly_rate' => $rate],
                    ]);
                }
            }
        });

        EmployeeRatesUpdated::dispatch($target->id);
    }
}
