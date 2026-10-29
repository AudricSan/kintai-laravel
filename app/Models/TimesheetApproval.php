<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class TimesheetApproval extends Model
{
    protected $table = 'timesheet_approvals';
    protected $guarded = [];
}
