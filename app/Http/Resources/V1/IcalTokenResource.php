<?php

namespace App\Http\Resources\V1;

use App\Models\IcalToken;
use App\Support\ApiDate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Lien iCal d'un employé pour un magasin. Le jeton n'est jamais relu (seule son empreinte est stockée) : `token` et `url`
 * ne sont donnés qu'à la création ou à la régénération, via `new IcalTokenResource($token, $plain)`.
 *
 * @mixin IcalToken
 */
class IcalTokenResource extends JsonResource
{
    public function __construct(IcalToken $resource, private readonly ?string $plainToken = null)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<int|string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'store_id' => $this->store_id,
            'store_name' => $this->whenLoaded('store', fn (): string => $this->store->name),
            'created_at' => ApiDate::iso($this->created_at),
            'updated_at' => ApiDate::iso($this->updated_at),
            $this->mergeWhen($this->plainToken !== null, fn (): array => [
                'token' => $this->plainToken,
                'url' => route('ical.feed', ['token' => $this->plainToken, 'store' => $this->store_id]),
            ]),
        ];
    }
}
