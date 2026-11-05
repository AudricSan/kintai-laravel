<?php

namespace Kintai\Bundles\Notebook\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Une note telle que l'API la rend (tableau préparé par `EntryController`).
 *
 * @property array<string, mixed> $resource
 */
class EntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
