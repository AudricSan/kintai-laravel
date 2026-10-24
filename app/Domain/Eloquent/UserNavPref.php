<?php
declare(strict_types=1);

namespace kintai\Domain\Eloquent;

use Illuminate\Database\Eloquent\Model;

final class UserNavPref extends Model
{
    protected $table = 'user_nav_prefs';
    protected $guarded = [];
    public $timestamps = false;
}
