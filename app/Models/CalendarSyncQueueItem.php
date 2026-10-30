<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class CalendarSyncQueueItem extends Model
{
    protected $table = 'calendar_sync_queue';
    protected $guarded = [];
    public $timestamps = false;

    /** Met (ou remet) une source en tête de la file de synchronisation. */
    public static function enqueue(int $connectionId, string $sourceType, int $sourceId): void
    {
        static::updateOrCreate(
            ['connection_id' => $connectionId, 'source_type' => $sourceType, 'source_id' => $sourceId],
            ['attempts' => 0, 'next_attempt_at' => null, 'last_error' => null],
        );
    }

    /** Éléments à traiter maintenant, du plus ancien au plus récent. */
    public static function due(int $limit): array
    {
        return static::where(function ($query): void {
            $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', date('Y-m-d H:i:s'));
        })->orderBy('id')->limit(max(1, $limit))->get()->toArray();
    }

    public static function done(int $queueId): void
    {
        static::where('id', $queueId)->delete();
    }

    public static function postpone(int $queueId, int $attempts, string $nextAttemptAt, string $error): void
    {
        static::where('id', $queueId)->update(['attempts' => $attempts, 'next_attempt_at' => $nextAttemptAt, 'last_error' => mb_substr($error, 0, 500)]);
    }}
