<?php
declare(strict_types=1);

namespace App\Models;

use App\Extension\DashboardLayout;
use Illuminate\Database\Eloquent\Model;

final class RoleDashboardLayout extends Model
{
    protected $table = 'dashboard_role_layouts';
    protected $guarded = [];
    public $timestamps = false;
    public $incrementing = false;
    protected $primaryKey = 'role_id';

    /** Disposition par défaut d'un rôle pour un type de tableau de bord, normalisée ; null si le rôle n'en a pas. */
    public static function layoutFor(int $roleId, string $dashboardType): ?array
    {
        $raw     = static::where('role_id', $roleId)->where('dashboard_type', $dashboardType)->value('layout');
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($decoded) ? DashboardLayout::normalize($decoded) : null;
    }

    /** Enregistre (crée ou remplace) la disposition par défaut d'un rôle. */
    public static function put(int $roleId, string $dashboardType, array $layout): void
    {
        $json  = json_encode(DashboardLayout::normalize($layout), JSON_THROW_ON_ERROR);
        $query = static::where('role_id', $roleId)->where('dashboard_type', $dashboardType);
        if ($query->exists()) {
            $query->update(['layout' => $json, 'updated_at' => date('Y-m-d H:i:s')]);

            return;
        }
        static::create(['role_id' => $roleId, 'dashboard_type' => $dashboardType, 'layout' => $json, 'updated_at' => date('Y-m-d H:i:s')]);
    }}
