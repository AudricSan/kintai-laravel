<?php

namespace Kintai\Bundles\Timeclock\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un pointage tel que l'API le rend (tableau préparé par `EntryController`).
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
