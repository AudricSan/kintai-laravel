<?php

declare(strict_types=1);

namespace kintai\Domain\Eloquent;

use Illuminate\Database\Eloquent\Model;

final class ActivityEntry extends Model
{
    protected $table = 'activity_log';
    protected $guarded = [];
    public $timestamps = false;
}
