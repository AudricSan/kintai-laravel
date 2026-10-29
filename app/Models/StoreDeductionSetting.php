<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class StoreDeductionSetting extends Model
{
    protected $table = 'store_deduction_settings';
    protected $guarded = [];
    public $timestamps = false;
}
