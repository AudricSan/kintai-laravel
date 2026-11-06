<?php

namespace Kintai\Bundles\PlanningCheck\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Une validation telle que l'API la rend (tableau préparé par `CheckController`).
 *
 * @property array<string, mixed> $resource
 */
class CheckResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
