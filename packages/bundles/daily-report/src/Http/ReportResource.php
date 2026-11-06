<?php

namespace Kintai\Bundles\DailyReport\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un rapport tel que l'API le rend (tableau préparé par `ReportController`).
 *
 * @property array<string, mixed> $resource
 */
class ReportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
