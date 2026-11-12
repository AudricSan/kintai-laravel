<?php

namespace App\Http\Resources\V1;

use App\Models\Availability;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Disponibilité d'un employé pour un jour ISO de la semaine (1 = lundi ... 7 = dimanche) dans un magasin.
 *
 * @mixin Availability
 */
class AvailabilityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'store_id' => $this->store_id,
            'day_of_week' => $this->day_of_week,
            'is_available' => $this->is_available,
            'start' => $this->starts_at,
            'end' => $this->ends_at,
        ];
    }
}
