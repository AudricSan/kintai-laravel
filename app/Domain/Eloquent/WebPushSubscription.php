<?php

declare(strict_types=1);

namespace kintai\Domain\Eloquent;

use Illuminate\Database\Eloquent\Model;

final class WebPushSubscription extends Model
{
    protected $table = 'web_push_subscriptions';
    protected $guarded = [];
    public $timestamps = false;
}
