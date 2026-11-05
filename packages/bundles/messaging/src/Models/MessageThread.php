<?php

namespace Kintai\Bundles\Messaging\Models;

use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Une conversation d'un magasin entre ses participants.
 *
 * @property int $id
 * @property int $store_id
 * @property int $creator_id
 * @property string $subject
 * @property Carbon|null $last_message_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Store $store
 * @property-read User|null $creator
 * @property-read ThreadMessage|null $latestMessage
 */
#[Fillable(['store_id', 'creator_id', 'subject', 'last_message_at'])]
class MessageThread extends Model
{
    protected $table = 'message_threads';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['last_message_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id')->withTrashed();
    }

    /**
     * @return HasMany<ThreadParticipant, $this>
     */
    public function participants(): HasMany
    {
        return $this->hasMany(ThreadParticipant::class, 'thread_id');
    }

    /**
     * @return HasMany<ThreadMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(ThreadMessage::class, 'thread_id');
    }

    /**
     * @return HasOne<ThreadMessage, $this>
     */
    public function latestMessage(): HasOne
    {
        return $this->hasOne(ThreadMessage::class, 'thread_id')->latestOfMany(['sent_at', 'id']);
    }
}
