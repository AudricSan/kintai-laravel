<?php

namespace Kintai\Bundles\ShiftClaim\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Une candidature ou un shift de la bourse tel que l'API le rend (tableau préparé par `ClaimController`).
 *
 * @property array<string, mixed> $resource
 */
class ClaimResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
