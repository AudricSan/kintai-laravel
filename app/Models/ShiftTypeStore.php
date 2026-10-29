<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ShiftTypeStore extends Model
{
    protected $table = 'shift_type_stores';
    protected $guarded = [];
    public $timestamps = false;
    public $incrementing = false;
}
