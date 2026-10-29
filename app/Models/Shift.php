<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class Shift extends Model
{
    protected $table = 'shifts';
    protected $guarded = [];
    public $timestamps = false;
}
