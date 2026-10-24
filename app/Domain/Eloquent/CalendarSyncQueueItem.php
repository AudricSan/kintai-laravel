<?php

declare(strict_types=1);

namespace kintai\Domain\Eloquent;

use Illuminate\Database\Eloquent\Model;

final class CalendarSyncQueueItem extends Model
{
    protected $table = 'calendar_sync_queue';
    protected $guarded = [];
    public $timestamps = false;
}
