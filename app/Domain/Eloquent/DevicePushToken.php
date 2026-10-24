<?php
declare(strict_types=1);

namespace kintai\Domain\Eloquent;

use Illuminate\Database\Eloquent\Model;

final class DevicePushToken extends Model
{
    protected $table = 'device_push_tokens';
    protected $guarded = [];
    public $timestamps = false;
}
