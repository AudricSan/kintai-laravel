<?php

namespace Kintai\Bundles\Messaging\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * La participation d'un utilisateur à une conversation : lu / non lu et conversation masquée de sa liste.
 *
 * @property int $id
 * @property int $thread_id
 * @property int $user_id
 * @property bool $is_read
 * @property Carbon|null $last_read_at
 * @property Carbon|null $hidden_at
 * @property-read MessageThread $thread
 * @property-read User|null $user
 */
#[Fillable(['thread_id', 'user_id', 'is_read', 'last_read_at', 'hidden_at'])]
class ThreadParticipant extends Model
{
    protected $table = 'thread_participants';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_read' => 'boolean', 'last_read_at' => 'datetime', 'hidden_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<MessageThread, $this>
     */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(MessageThread::class, 'thread_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
