<?php
declare(strict_types=1);

namespace kintai\Domain\Eloquent;

use Illuminate\Database\Eloquent\Model;

final class UserDashboardPref extends Model
{
    protected $table = 'user_dashboard_prefs';
    protected $guarded = [];
    public $timestamps = false;
}
