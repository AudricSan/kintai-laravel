<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class NotificationPreference extends Model
{
    protected $table = 'notification_preferences';
    protected $guarded = [];
    public $timestamps = false;
    protected $casts = ['enabled' => 'boolean'];
}
