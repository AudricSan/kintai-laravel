<?php

namespace App\Http\Resources\V1;

use App\Models\Role;
use App\Models\RoleAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Rôle. `store_id` n'est donné que pour une attribution (pivot chargé ; null = rôle global).
 *
 * @mixin Role
 */
class RoleResource extends JsonResource
{
    /**
     * @return array<int|string, mixed>
     */
    public function toArray(Request $request): array
    {
        $pivot = $this->resource->pivot ?? null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'is_system' => $this->is_system,
            'is_manager' => $this->is_manager,
            $this->mergeWhen($pivot instanceof RoleAssignment, fn (): array => ['store_id' => $pivot->store_id]),
        ];
    }
}
