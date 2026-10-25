<?php

declare(strict_types=1);

namespace kintai\Domain\Eloquent;

use Illuminate\Database\Eloquent\Model;

final class ShiftWeekTemplate extends Model
{
    protected $table = 'shift_week_templates';
    protected $guarded = [];
}
