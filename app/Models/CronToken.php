<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class CronToken extends Model
{
    protected $table = 'cron_tokens';
    protected $guarded = [];
    public $timestamps = false;
}
