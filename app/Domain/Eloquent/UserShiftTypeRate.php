<?php
declare(strict_types=1);

namespace kintai\Domain\Eloquent;

use Illuminate\Database\Eloquent\Model;

final class UserShiftTypeRate extends Model
{
    protected $table = 'user_shift_type_rates';
    protected $guarded = [];
    public $timestamps = false;
}
