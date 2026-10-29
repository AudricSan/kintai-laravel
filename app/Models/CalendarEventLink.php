<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class CalendarEventLink extends Model
{
    protected $table = 'calendar_event_links';
    protected $guarded = [];
    public $timestamps = false;
}
