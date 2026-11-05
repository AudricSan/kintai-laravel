<?php

namespace Kintai\Bundles\ShiftSwap\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Une demande d'échange telle que l'API la rend (tableau préparé par `SwapController`).
 *
 * @property array<string, mixed> $resource
 */
class SwapResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
