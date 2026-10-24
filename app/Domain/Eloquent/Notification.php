<?php
declare(strict_types=1);

namespace kintai\Domain\Eloquent;

use Illuminate\Database\Eloquent\Model;

final class Notification extends Model
{
    protected $table = 'notifications';
    protected $guarded = [];
    public $timestamps = false;
}
