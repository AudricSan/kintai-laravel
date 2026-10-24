<?php

declare(strict_types=1);

namespace kintai\Core\Repositories;

use kintai\Core\Extension\DashboardLayout;
use kintai\Domain\Eloquent\RoleDashboardLayout as EloquentRoleLayout;

final class DatabaseRoleDashboardLayoutRepository implements RoleDashboardLayoutRepositoryInterface
{
    public function get(int $roleId, string $dashboardType): ?array
    {
        $raw = EloquentRoleLayout::where('role_id', $roleId)->where('dashboard_type', $dashboardType)->value('layout');
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($decoded) ? DashboardLayout::normalize($decoded) : null;
    }

    public function save(int $roleId, string $dashboardType, array $layout): void
    {
        $json = json_encode(DashboardLayout::normalize($layout), JSON_THROW_ON_ERROR);
        $query = EloquentRoleLayout::where('role_id', $roleId)->where('dashboard_type', $dashboardType);
        if ($query->exists()) {
            $query->update(['layout' => $json, 'updated_at' => date('Y-m-d H:i:s')]);
            return;
        }
        EloquentRoleLayout::create(['role_id' => $roleId, 'dashboard_type' => $dashboardType, 'layout' => $json, 'updated_at' => date('Y-m-d H:i:s')]);
    }

    public function delete(int $roleId, string $dashboardType): void
    {
        EloquentRoleLayout::where('role_id', $roleId)->where('dashboard_type', $dashboardType)->delete();
    }

    public function deleteForRole(int $roleId): void
    {
        EloquentRoleLayout::where('role_id', $roleId)->delete();
    }
}
