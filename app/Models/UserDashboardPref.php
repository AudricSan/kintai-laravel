<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class UserDashboardPref extends Model
{
    protected $table = 'user_dashboard_prefs';
    protected $guarded = [];
    public $timestamps = false;
}
