<?php

declare(strict_types=1);

namespace kintai\Domain\Eloquent;

use Illuminate\Database\Eloquent\Model;

final class ShiftDeletionLog extends Model
{
    protected $table = 'shift_deletion_log';
    protected $guarded = [];
    public $timestamps = false;
}
