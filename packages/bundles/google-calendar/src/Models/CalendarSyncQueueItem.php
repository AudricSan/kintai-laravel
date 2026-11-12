<?php

namespace Kintai\Bundles\GoogleCalendar\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Une source (shift ou congé) à réconcilier avec l'agenda. Une seule ligne par (liaison, source) : l'opération (créer,
 * modifier, supprimer, rien) se déduit de l'état réel de la source au moment du traitement.
 *
 * @property int $id
 * @property int $connection_id
 * @property string $source_type
 * @property string $source_key
 * @property int $attempts
 * @property Carbon|null $next_attempt_at
 * @property string|null $last_error
 * @property Carbon|null $created_at
 * @property-read CalendarConnection $connection
 */
#[Fillable(['connection_id', 'source_type', 'source_key', 'attempts', 'next_attempt_at', 'last_error'])]
class CalendarSyncQueueItem extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'google_calendar_sync_queue';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['attempts' => 'integer', 'next_attempt_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<CalendarConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(CalendarConnection::class, 'connection_id');
    }
}
