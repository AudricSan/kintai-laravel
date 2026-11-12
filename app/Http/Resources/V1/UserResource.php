<?php

namespace App\Http\Resources\V1;

use App\Models\User;
use App\Support\ApiDate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Compte d'un utilisateur. Jamais de mot de passe, de secret 2FA, de jeton ni de code de récupération.
 * Les champs privés (e-mail, code employé, langue, état du compte) ne sont donnés qu'à l'utilisateur lui-même.
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    private bool $asSelf = false;

    /**
     * Pour une réponse destinée à l'utilisateur lui-même alors que la requête n'est pas encore authentifiée (connexion).
     */
    public function asSelf(): static
    {
        $this->asSelf = true;

        return $this;
    }

    /**
     * @return array<int|string, mixed>
     */
    public function toArray(Request $request): array
    {
        $viewer = $request->user('sanctum');
        $self = $this->asSelf || ($viewer instanceof User && $viewer->is($this->resource));

        return [
            'id' => $this->id,
            'name' => $this->name,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'furigana_first_name' => $this->furigana_first_name,
            'furigana_last_name' => $this->furigana_last_name,
            'color' => $this->color,
            'has_avatar' => $this->avatar_path !== null && $this->avatar_path !== '',
            $this->mergeWhen($self, fn (): array => [
                'email' => $this->email,
                'employee_code' => $this->employee_code,
                'locale' => $this->locale,
                'is_active' => $this->is_active,
                'must_change_password' => $this->must_change_password,
                'two_factor_enabled' => $this->hasEnabledTwoFactorAuthentication(),
                'created_at' => ApiDate::iso($this->created_at),
            ]),
        ];
    }
}
