<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class CalendarEventLink extends Model
{
    protected $table = 'calendar_event_links';
    protected $guarded = [];
    public $timestamps = false;

    public static function lookup(int $connectionId, string $sourceType, int $sourceId): ?array
    {
        return static::where('connection_id', $connectionId)->where('source_type', $sourceType)->where('source_id', $sourceId)->first()?->toArray();
    }

    public static function forConnection(int $connectionId, string $sourceType): array
    {
        return static::where('connection_id', $connectionId)->where('source_type', $sourceType)->get()->toArray();
    }

    public static function remember(int $connectionId, string $sourceType, int $sourceId, string $externalEventId, string $hash): void
    {
        static::updateOrCreate(
            ['connection_id' => $connectionId, 'source_type' => $sourceType, 'source_id' => $sourceId],
            ['external_event_id' => $externalEventId, 'content_hash' => $hash, 'synced_at' => date('Y-m-d H:i:s')],
        );
    }

    public static function forget(int $connectionId, string $sourceType, int $sourceId): void
    {
        static::where('connection_id', $connectionId)->where('source_type', $sourceType)->where('source_id', $sourceId)->delete();
    }}
