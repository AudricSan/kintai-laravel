<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ActivityEntry extends Model
{
    protected $table = 'activity_log';
    protected $guarded = [];
    public $timestamps = false;
}
