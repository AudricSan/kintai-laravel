<?php

declare(strict_types=1);

namespace kintai\Domain\Eloquent;

use Illuminate\Database\Eloquent\Model;

final class TimesheetApproval extends Model
{
    protected $table = 'timesheet_approvals';
    protected $guarded = [];
}
