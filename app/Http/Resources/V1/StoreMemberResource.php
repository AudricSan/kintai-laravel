<?php

namespace App\Http\Resources\V1;

use App\Models\StoreMembership;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Membre d'un magasin : l'employé, sa fiche dans ce magasin (pivot) et son rôle dans ce magasin (relation `roles` chargée).
 *
 * @mixin User
 */
class StoreMemberResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $pivot = $this->resource->getRelation('pivot');
        $storeId = $pivot instanceof StoreMembership ? $pivot->store_id : null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'employee_code' => $this->employee_code,
            'color' => $this->color,
            'store_id' => $storeId,
            'staff_code' => $pivot instanceof StoreMembership ? $pivot->staff_code : null,
            'hire_date' => $pivot instanceof StoreMembership ? $pivot->hire_date?->toDateString() : null,
            'contract_type' => $pivot instanceof StoreMembership ? $pivot->contract_type : null,
            'is_active' => $pivot instanceof StoreMembership ? $pivot->is_active : null,
            'account_is_active' => $this->is_active,
            'roles' => $this->whenLoaded('roles', fn (): array => $this->roles
                ->filter(fn ($role): bool => $role->getRelation('pivot')->store_id === $storeId)
                ->map(fn ($role): array => ['id' => $role->id, 'name' => $role->name, 'slug' => $role->slug])
                ->values()
                ->all()),
        ];
    }
}
