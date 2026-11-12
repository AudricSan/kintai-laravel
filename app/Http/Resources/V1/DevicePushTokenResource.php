<?php

namespace App\Http\Resources\V1;

use App\Models\DevicePushToken;
use App\Support\ApiDate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Appareil mobile enregistré pour le push (sans la valeur du jeton FCM).
 *
 * @mixin DevicePushToken
 */
class DevicePushTokenResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'platform' => $this->platform,
            'last_used_at' => ApiDate::iso($this->last_used_at),
            'created_at' => ApiDate::iso($this->created_at),
        ];
    }
}
