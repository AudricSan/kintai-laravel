<?php
declare(strict_types=1);

namespace kintai\Domain\Eloquent;

use Illuminate\Database\Eloquent\Model;

final class CronToken extends Model
{
    protected $table = 'cron_tokens';
    protected $guarded = [];
    public $timestamps = false;
}
