<?php
declare(strict_types=1);

namespace kintai\Domain\Eloquent;

use Illuminate\Database\Eloquent\Model;

final class UserDashboardLayout extends Model
{
    protected $table = 'user_dashboard_layouts';
    protected $guarded = [];
    public $timestamps = false;
    protected $casts = ['is_active' => 'boolean'];
}
