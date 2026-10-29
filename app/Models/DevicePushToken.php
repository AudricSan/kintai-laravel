<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class DevicePushToken extends Model
{
    protected $table = 'device_push_tokens';
    protected $guarded = [];
    public $timestamps = false;
}
