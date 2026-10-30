<?php
declare(strict_types=1);

namespace App\Models;

use App\Extension\DashboardLayout;
use Illuminate\Database\Eloquent\Model;

final class UserDashboardPref extends Model
{
    protected $table = 'user_dashboard_prefs';
    protected $guarded = [];
    public $timestamps = false;

    public static function getEnabledWidgets(int $userId, string $dashboardType): ?array
    {
        $record = static::where('user_id', $userId)
            ->where('dashboard_type', $dashboardType)
            ->first();
        if ($record === null) {
            return null;
        }
        $decoded = json_decode($record['widgets'] ?? '[]', true);
        return is_array($decoded) ? $decoded : null;
    }

    public static function saveWidgets(int $userId, array $widgets, string $dashboardType): void
    {
        $data = [
            'user_id'        => $userId,
            'dashboard_type' => $dashboardType,
            'widgets'        => json_encode(array_values($widgets)),
            'updated_at'     => date('Y-m-d H:i:s'),
        ];

        static::updateOrCreate(
            ['user_id' => $userId, 'dashboard_type' => $dashboardType],
            $data,
        );
    }

    public static function getLayout(int $userId, string $dashboardType): ?array
    {
        $raw = static::where('user_id', $userId)
            ->where('dashboard_type', $dashboardType)
            ->value('layout');
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($decoded) ? DashboardLayout::normalize($decoded) : null;
    }

    public static function saveLayout(int $userId, string $dashboardType, ?array $layout): void
    {
        $json = $layout === null ? null : json_encode(DashboardLayout::normalize($layout), JSON_THROW_ON_ERROR);
        $record = static::where('user_id', $userId)->where('dashboard_type', $dashboardType)->first();
        if ($record !== null) {
            $record->update(['layout' => $json, 'updated_at' => date('Y-m-d H:i:s')]);
            return;
        }
        // Pas encore de préférences : widgets « null » = sélection par défaut (getEnabledWidgets() renvoie null),
        // pour que déplacer un widget ne fige pas la liste des widgets activés.
        static::create([
            'user_id'        => $userId,
            'dashboard_type' => $dashboardType,
            'widgets'        => 'null',
            'layout'         => $json,
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);
    }
}
