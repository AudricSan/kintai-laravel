<?php

namespace Kintai\Bundles\Messaging\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Une conversation, un message ou un participant tel que l'API le rend (tableau préparé par `MessageController`).
 *
 * @property array<string, mixed> $resource
 */
class MessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
