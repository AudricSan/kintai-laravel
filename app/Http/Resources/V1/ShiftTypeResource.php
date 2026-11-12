<?php

namespace App\Http\Resources\V1;

use App\Models\ShiftType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Type de shift : plage horaire locale (`starts_at` / `ends_at` en "H:i"), taux horaire, magasins (`store_ids` si chargés).
 *
 * @mixin ShiftType
 */
class ShiftTypeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'starts_at' => $this->starts_at,
            'ends_at' => $this->ends_at,
            'hourly_rate' => number_format((float) $this->hourly_rate, 2, '.', ''),
            'color' => $this->color,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'store_ids' => $this->whenLoaded('stores', fn (): array => $this->stores->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values()->all()),
        ];
    }
}
