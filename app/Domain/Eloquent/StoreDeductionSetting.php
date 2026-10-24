<?php

declare(strict_types=1);

namespace kintai\Domain\Eloquent;

use Illuminate\Database\Eloquent\Model;

final class StoreDeductionSetting extends Model
{
    protected $table = 'store_deduction_settings';
    protected $guarded = [];
    public $timestamps = false;
}
