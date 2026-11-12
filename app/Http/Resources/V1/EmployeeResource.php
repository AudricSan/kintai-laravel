<?php

namespace App\Http\Resources\V1;

use App\Authorization\Authorizer;
use App\Models\Store;
use App\Models\StoreMembership;
use App\Models\User;
use App\Support\ApiDate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Fiche d'un employé pour un lecteur autorisé (soi-même, ou un gérant qui a `employees.view`). Jamais de mot de passe,
 * de secret 2FA ni de jeton. Les données RH (`profile`), les rôles et les magasins ne sont donnés que si la relation est chargée.
 *
 * @mixin User
 */
class EmployeeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'furigana_first_name' => $this->furigana_first_name,
            'furigana_last_name' => $this->furigana_last_name,
            'email' => $this->email,
            'employee_code' => $this->employee_code,
            'color' => $this->color,
            'locale' => $this->locale,
            'is_active' => $this->is_active,
            'is_owner' => app(Authorizer::class)->isOwner($this->resource),
            'must_change_password' => $this->must_change_password,
            'has_avatar' => $this->avatar_path !== null && $this->avatar_path !== '',
            'created_at' => ApiDate::iso($this->created_at),
            'stores' => $this->whenLoaded('stores', fn (): array => $this->stores->map(function (Store $store): array {
                $pivot = $store->getRelation('pivot');

                return [
                    'id' => $store->id,
                    'name' => $store->name,
                    'code' => $store->code,
                    'staff_code' => $pivot instanceof StoreMembership ? $pivot->staff_code : null,
                    'hire_date' => $pivot instanceof StoreMembership ? $pivot->hire_date?->toDateString() : null,
                    'contract_type' => $pivot instanceof StoreMembership ? $pivot->contract_type : null,
                    'is_active' => $pivot instanceof StoreMembership ? $pivot->is_active : true,
                ];
            })->all()),
            'roles' => $this->whenLoaded('roles', fn () => RoleResource::collection($this->roles)),
            'profile' => $this->whenLoaded('profile', fn (): ?array => $this->profile === null ? null : [
                'gender' => $this->profile->gender,
                'birth_date' => $this->profile->birth_date?->toDateString(),
                'address' => $this->profile->address,
                'postal_code' => $this->profile->postal_code,
                'phone' => $this->profile->phone,
                'mobile_phone' => $this->profile->mobile_phone,
                'tax_classification' => $this->profile->tax_classification,
                'education' => $this->profile->education,
                'guarantor_name' => $this->profile->guarantor_name,
                'guarantor_phone' => $this->profile->guarantor_phone,
            ]),
        ];
    }
}
