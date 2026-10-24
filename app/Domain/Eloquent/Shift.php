<?php

declare(strict_types=1);

namespace kintai\Domain\Eloquent;

use Illuminate\Database\Eloquent\Model;

final class Shift extends Model
{
    protected $table = 'shifts';
    protected $guarded = [];
    public $timestamps = false;
}
