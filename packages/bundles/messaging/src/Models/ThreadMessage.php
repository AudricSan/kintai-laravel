<?php

namespace Kintai\Bundles\Messaging\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un message d'une conversation. Le contenu est du texte brut : il est toujours échappé à l'affichage.
 *
 * @property int $id
 * @property int $thread_id
 * @property int $sender_id
 * @property string $body
 * @property Carbon|null $erased_at
 * @property Carbon $sent_at
 * @property-read MessageThread $thread
 * @property-read User|null $sender
 */
#[Fillable(['thread_id', 'sender_id', 'body', 'erased_at', 'sent_at'])]
class ThreadMessage extends Model
{
    protected $table = 'thread_messages';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['erased_at' => 'datetime', 'sent_at' => 'datetime'];
    }

    public function isErased(): bool
    {
        return $this->erased_at !== null;
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
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id')->withTrashed();
    }
}
