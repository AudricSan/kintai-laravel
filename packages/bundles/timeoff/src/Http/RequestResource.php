<?php

namespace Kintai\Bundles\Timeoff\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Une demande telle que l'API la rend (tableau préparé par `RequestController`).
 *
 * @property array<string, mixed> $resource
 */
class RequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
