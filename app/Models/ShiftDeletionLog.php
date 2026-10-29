<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ShiftDeletionLog extends Model
{
    protected $table = 'shift_deletion_log';
    protected $guarded = [];
    public $timestamps = false;
}
