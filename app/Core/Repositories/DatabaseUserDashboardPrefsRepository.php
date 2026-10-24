<?php
declare(strict_types=1);

namespace kintai\Core\Repositories;

use kintai\Core\Extension\DashboardLayout;
use kintai\Domain\Eloquent\UserDashboardPref as EloquentUserDashboardPref;

final class DatabaseUserDashboardPrefsRepository implements UserDashboardPrefsRepositoryInterface
{
    public function __construct() {}

    public function getEnabledWidgets(int $userId, string $dashboardType): ?array
    {
        $record = EloquentUserDashboardPref::where('user_id', $userId)
            ->where('dashboard_type', $dashboardType)
            ->first();
        if ($record === null) {
            return null;
        }
        $decoded = json_decode($record['widgets'] ?? '[]', true);
        return is_array($decoded) ? $decoded : null;
    }

    public function saveWidgets(int $userId, array $widgets, string $dashboardType): void
    {
        $data = [
            'user_id'        => $userId,
            'dashboard_type' => $dashboardType,
            'widgets'        => json_encode(array_values($widgets)),
            'updated_at'     => date('Y-m-d H:i:s'),
        ];

        EloquentUserDashboardPref::updateOrCreate(
            ['user_id' => $userId, 'dashboard_type' => $dashboardType],
            $data,
        );
    }

    public function getLayout(int $userId, string $dashboardType): ?array
    {
        $raw = EloquentUserDashboardPref::where('user_id', $userId)
            ->where('dashboard_type', $dashboardType)
            ->value('layout');
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($decoded) ? DashboardLayout::normalize($decoded) : null;
    }

    public function saveLayout(int $userId, string $dashboardType, ?array $layout): void
    {
        $json = $layout === null ? null : json_encode(DashboardLayout::normalize($layout), JSON_THROW_ON_ERROR);
        $record = EloquentUserDashboardPref::where('user_id', $userId)->where('dashboard_type', $dashboardType)->first();
        if ($record !== null) {
            $record->update(['layout' => $json, 'updated_at' => date('Y-m-d H:i:s')]);
            return;
        }
        // Pas encore de préférences : widgets « null » = sélection par défaut (getEnabledWidgets() renvoie null),
        // pour que déplacer un widget ne fige pas la liste des widgets activés.
        EloquentUserDashboardPref::create([
            'user_id'        => $userId,
            'dashboard_type' => $dashboardType,
            'widgets'        => 'null',
            'layout'         => $json,
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);
    }
}
