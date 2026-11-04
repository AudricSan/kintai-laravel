<?php

namespace App\Http\Resources\V1;

use App\Support\ApiDate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Jeton d'accès de l'API : jamais sa valeur (seule l'empreinte est en base). `current` : le jeton de la requête.
 *
 * @mixin PersonalAccessToken
 */
class AccessTokenResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $current = $request->user('sanctum')?->currentAccessToken();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'created_at' => ApiDate::iso($this->created_at),
            'last_used_at' => ApiDate::iso($this->last_used_at),
            'expires_at' => ApiDate::iso($this->expires_at),
            'current' => $current instanceof PersonalAccessToken && $current->getKey() === $this->id,
        ];
    }
}
