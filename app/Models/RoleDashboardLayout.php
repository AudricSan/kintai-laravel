<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class RoleDashboardLayout extends Model
{
    protected $table = 'dashboard_role_layouts';
    protected $guarded = [];
    public $timestamps = false;
    public $incrementing = false;
    protected $primaryKey = 'role_id';
}
