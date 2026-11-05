<?php

namespace App\Http\Resources\V1;

use App\Activity\ActivityActionRegistry;
use App\Activity\ActivityDetails;
use App\Models\ActivityEntry;
use App\Support\ApiDate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Entrée du journal d'activité. `label`, `target` et `details` sont traduits dans la langue de la requête ;
 * `action` et `properties` sont stables. Les secrets n'y figurent jamais (filtrés à l'écriture).
 *
 * @mixin ActivityEntry
 */
class ActivityEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'label' => app(ActivityActionRegistry::class)->label($this->action),
            'actor' => $this->actor === null ? null : ['id' => $this->actor->id, 'name' => $this->actor->name],
            'store' => $this->store === null ? null : ['id' => $this->store->id, 'name' => $this->store->name],
            'subject' => $this->subject_type === null ? null : ['type' => class_basename($this->subject_type), 'id' => $this->subject_id],
            'target' => ActivityDetails::target($this->resource),
            'details' => ActivityDetails::lines($this->resource),
            'properties' => $this->properties ?? (object) [],
            'ip_address' => $this->ip_address,
            'created_at' => ApiDate::iso($this->created_at),
        ];
    }
}
