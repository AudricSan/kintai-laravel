<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class WebPushSubscription extends Model
{
    protected $table = 'web_push_subscriptions';
    protected $guarded = [];
    public $timestamps = false;
}
