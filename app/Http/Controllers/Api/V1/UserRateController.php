<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Employees\UpdateEmployeeRates;
use App\Http\Resources\V1\UserRateResource;
use App\Models\ShiftType;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Taux horaires personnels d'un employé. L'identifiant d'un taux est celui de son type de shift. Lecture : soi-même ou
 * `payroll.view` ; écriture : `employees.update` (action UpdateEmployeeRates).
 */
class UserRateController extends ApiController
{
    public function index(Request $request, int $user): AnonymousResourceCollection
    {
        $target = $this->targetUser($request, $user, 'payroll.view');

        return UserRateResource::collection($target->personalRates()->orderBy('shift_types.sort_order')->orderBy('shift_types.id')->get());
    }

    public function show(Request $request, int $user, int $rate): UserRateResource
    {
        $target = $this->targetUser($request, $user, 'payroll.view');

        return new UserRateResource($this->existing($target, $rate));
    }

    public function store(Request $request, int $user, UpdateEmployeeRates $rates): JsonResponse
    {
        $target = $this->targetUser($request, $user, 'employees.update');
        $data = $request->validate(['shift_type_id' => ['required', 'integer'], 'hourly_rate' => ['required', 'numeric']]);

        if ($target->personalRates()->where('shift_types.id', $data['shift_type_id'])->exists()) {
            throw ValidationException::withMessages(['shift_type_id' => __('This employee already has a personal rate for this shift type.')]);
        }

        $rates->handle($this->actor($request), $target, [$data['shift_type_id'] => $data['hourly_rate']]);

        return (new UserRateResource($this->existing($target, (int) $data['shift_type_id'])))->response()->setStatusCode(201);
    }

    public function update(Request $request, int $user, int $rate, UpdateEmployeeRates $rates): UserRateResource
    {
        $target = $this->targetUser($request, $user, 'employees.update');
        $this->existing($target, $rate);
        $data = $request->validate(['hourly_rate' => ['required', 'numeric']]);

        $rates->handle($this->actor($request), $target, [$rate => $data['hourly_rate']]);

        return new UserRateResource($this->existing($target, $rate));
    }

    public function destroy(Request $request, int $user, int $rate, UpdateEmployeeRates $rates): Response
    {
        $target = $this->targetUser($request, $user, 'employees.update');
        $this->existing($target, $rate);

        $rates->handle($this->actor($request), $target, [$rate => null]);

        return response()->noContent();
    }

    private function existing(User $target, int $shiftTypeId): ShiftType
    {
        return $target->personalRates()->where('shift_types.id', $shiftTypeId)->firstOrFail();
    }
}
