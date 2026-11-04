<?php

namespace App\Http\Resources\V1;

use App\Models\Store;
use App\Models\StoreMembership;
use App\Support\ApiDate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Magasin. Si la relation vient d'un utilisateur (`pivot` chargé), l'appartenance de cet utilisateur est ajoutée.
 *
 * @mixin Store
 */
class StoreResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $pivot = $this->resource->pivot ?? null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'timezone' => $this->timezone,
            'currency' => $this->currency,
            'locale' => $this->locale,
            'is_active' => $this->is_active,
            'week_start_day' => $this->week_start_day,
            'address_street' => $this->address_street,
            'address_city' => $this->address_city,
            'address_postal' => $this->address_postal,
            'address_country' => $this->address_country,
            'phone' => $this->phone,
            'email' => $this->email,
            'created_at' => ApiDate::iso($this->created_at),
            'membership' => $this->when($pivot instanceof StoreMembership, fn (): array => [
                'staff_code' => $pivot->staff_code,
                'hire_date' => $pivot->hire_date?->toDateString(),
                'contract_type' => $pivot->contract_type,
                'is_active' => $pivot->is_active,
            ]),
        ];
    }
}
