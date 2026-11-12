<?php

namespace App\Http\Resources\V1;

use App\Notifications\NotificationLink;
use App\Notifications\NotificationPresenter;
use App\Support\ApiDate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Notification de la cloche, titre et texte traduits dans la langue de la requête. `link` : chemin interne de
 * l'interface web (null si absent), `reference_*` : l'objet désigné.
 *
 * @mixin DatabaseNotification
 */
class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->data;
        $text = NotificationPresenter::present($this->type, $data);

        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $text['title'],
            'body' => $text['body'],
            'icon' => $text['icon'],
            'link' => NotificationLink::internal(is_string($data['link'] ?? null) ? $data['link'] : null),
            'reference_type' => is_string($data['reference_type'] ?? null) ? $data['reference_type'] : null,
            'reference_id' => is_int($data['reference_id'] ?? null) ? $data['reference_id'] : null,
            'read' => $this->read_at !== null,
            'read_at' => ApiDate::iso($this->read_at),
            'created_at' => ApiDate::iso($this->created_at),
        ];
    }
}
