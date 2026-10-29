<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class IcalToken extends Model
{
    protected $table = 'ical_tokens';
    protected $guarded = [];
    public $timestamps = false;
}
