<?php

namespace App\Models;

use Database\Factories\ActivityEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Une ligne du journal d'activité (audit métier). Jamais modifiée après coup : pas de `updated_at`.
 *
 * @property int $id
 * @property int|null $actor_id
 * @property string $action Clé d'action, ex. « store.created »
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property int|null $store_id
 * @property array<string, mixed>|null $properties
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon|null $created_at
 * @property-read User|null $actor
 * @property-read Store|null $store
 */
#[Fillable(['actor_id', 'action', 'subject_type', 'subject_id', 'store_id', 'properties', 'ip_address', 'user_agent', 'created_at'])]
class ActivityEntry extends Model
{
    /** @use HasFactory<ActivityEntryFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'properties' => 'array',
            'subject_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id')->withTrashed();
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class)->withTrashed();
    }
}
