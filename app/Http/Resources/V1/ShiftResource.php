<?php

namespace App\Http\Resources\V1;

use App\Authorization\Authorizer;
use App\Models\Shift;
use App\Models\User;
use App\Planning\ShiftWageCalculator;
use App\Support\ApiDate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shift. Les instants sont en UTC (`starts_at`, `ends_at`) ; `local` donne les mêmes heures dans le fuseau de l'instance
 * (`date` = jour de rattachement, `start` / `end` = "H:i"), c'est le format de saisie. Le coût et les surcharges de paie ne
 * sont donnés qu'avec `payroll.view` sur le magasin du shift.
 *
 * @mixin Shift
 */
class ShiftResource extends JsonResource
{
    /**
     * @return array<int|string, mixed>
     */
    public function toArray(Request $request): array
    {
        $viewer = $request->user('sanctum');
        $payroll = $viewer instanceof User && app(Authorizer::class)->allows($viewer, 'payroll.view', $this->store_id);

        return [
            'id' => $this->id,
            'store_id' => $this->store_id,
            'user_id' => $this->user_id,
            'is_open' => $this->user_id === null,
            'shift_type_id' => $this->shift_type_id,
            'starts_at' => ApiDate::iso($this->starts_at),
            'ends_at' => ApiDate::iso($this->ends_at),
            'local' => [
                'date' => $this->shift_date->toDateString(),
                'start' => $this->localStartsAt->format('H:i'),
                'end' => $this->localEndsAt->format('H:i'),
                'crosses_midnight' => $this->crossesMidnight,
            ],
            'pause_minutes' => $this->pause_minutes,
            'duration_minutes' => $this->durationMinutes,
            'net_minutes' => $this->netMinutes,
            'notes' => $this->notes,
            'replaced_by_id' => $this->replaced_by_id,
            'is_deleted' => $this->deleted_at !== null,
            'deleted_at' => ApiDate::iso($this->deleted_at),
            'created_by' => $this->created_by,
            'created_at' => ApiDate::iso($this->created_at),
            'updated_at' => ApiDate::iso($this->updated_at),
            'store' => $this->whenLoaded('store', fn (): array => ['id' => $this->store->id, 'name' => $this->store->name, 'currency' => $this->store->currency]),
            'user' => $this->whenLoaded('user', fn (): ?array => $this->user === null ? null : ['id' => $this->user->id, 'name' => $this->user->name, 'color' => $this->user->color]),
            'shift_type' => $this->whenLoaded('shiftType', fn (): ?array => $this->shiftType === null ? null : ['id' => $this->shiftType->id, 'name' => $this->shiftType->name, 'code' => $this->shiftType->code, 'color' => $this->shiftType->color]),
            $this->mergeWhen($payroll, fn (): array => [
                'hourly_rate_override' => $this->hourly_rate_override,
                'net_minutes_override' => $this->net_minutes_override,
                'cost' => round(app(ShiftWageCalculator::class)->forShift($this->resource)->total, 2),
            ]),
        ];
    }
}
