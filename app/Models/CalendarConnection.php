<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class CalendarConnection extends Model
{
    protected $table = 'calendar_connections';
    protected $guarded = [];
    public $timestamps = false;
}
