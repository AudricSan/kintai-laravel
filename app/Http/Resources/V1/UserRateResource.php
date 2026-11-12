<?php

namespace App\Http\Resources\V1;

use App\Models\ShiftType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Taux horaire personnel d'un employé pour un type de shift (relation `personalRates`, pivot chargé).
 * L'identifiant d'un taux est celui du type de shift.
 *
 * @mixin ShiftType
 */
class UserRateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'shift_type_id' => $this->id,
            'shift_type' => ['id' => $this->id, 'name' => $this->name, 'code' => $this->code],
            'hourly_rate' => number_format((float) $this->resource->getRelation('pivot')->hourly_rate, 2, '.', ''),
            'default_hourly_rate' => number_format((float) $this->hourly_rate, 2, '.', ''),
        ];
    }
}
