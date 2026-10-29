<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class Availability extends Model
{
    protected $table = 'availabilities';
    protected $guarded = [];
    public $timestamps = false;
}
