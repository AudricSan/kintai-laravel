<?php

declare(strict_types=1);

namespace kintai\Core\Repositories;

use kintai\Core\Extension\DashboardLayout;
use kintai\Domain\Eloquent\UserDashboardLayout as EloquentLayout;

final class DatabaseUserDashboardLayoutRepository implements UserDashboardLayoutRepositoryInterface
{
    public function forUser(int $userId, string $dashboardType): array
    {
        $rows = EloquentLayout::where('user_id', $userId)->where('dashboard_type', $dashboardType)
            ->orderBy('position')->orderBy('id')->get();
        return array_values(array_map(fn($row) => $this->row($row->toArray()), $rows->all()));
    }

    public function find(int $id): ?array
    {
        $row = EloquentLayout::find($id);
        return $row === null
            ? null
            : $this->row($row->toArray()) + ['user_id' => (int) $row->user_id, 'dashboard_type' => (string) $row->dashboard_type];
    }

    public function createActive(int $userId, string $dashboardType, string $name, array $layout): int
    {
        EloquentLayout::where('user_id', $userId)->where('dashboard_type', $dashboardType)->update(['is_active' => false]);
        $position = (int) EloquentLayout::where('user_id', $userId)->where('dashboard_type', $dashboardType)->max('position') + 1;
        $row = EloquentLayout::create([
            'user_id'        => $userId,
            'dashboard_type' => $dashboardType,
            'name'           => $name,
            'layout'         => json_encode(DashboardLayout::normalize($layout), JSON_THROW_ON_ERROR),
            'is_active'      => true,
            'position'       => $position,
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);
        return (int) $row->id;
    }

    public function rename(int $id, string $name): void
    {
        EloquentLayout::where('id', $id)->update(['name' => $name, 'updated_at' => date('Y-m-d H:i:s')]);
    }

    public function delete(int $id): void
    {
        EloquentLayout::where('id', $id)->delete();
    }

    public function activate(int $id): void
    {
        $row = EloquentLayout::find($id);
        if ($row === null) {
            return;
        }
        EloquentLayout::where('user_id', $row->user_id)->where('dashboard_type', $row->dashboard_type)->update(['is_active' => false]);
        $row->update(['is_active' => true]);
    }

    public function updateActive(int $userId, string $dashboardType, array $layout): void
    {
        EloquentLayout::where('user_id', $userId)->where('dashboard_type', $dashboardType)->where('is_active', true)->update([
            'layout'     => json_encode(DashboardLayout::normalize($layout), JSON_THROW_ON_ERROR),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function deactivateAll(int $userId, string $dashboardType): void
    {
        EloquentLayout::where('user_id', $userId)->where('dashboard_type', $dashboardType)->update(['is_active' => false]);
    }

    /** @return array{id: int, name: string, layout: array<string, mixed>, is_active: bool, position: int} */
    private function row(array $row): array
    {
        $decoded = json_decode((string) ($row['layout'] ?? ''), true);
        return [
            'id'        => (int) $row['id'],
            'name'      => (string) $row['name'],
            'layout'    => DashboardLayout::normalize(is_array($decoded) ? $decoded : []),
            'is_active' => (bool) $row['is_active'],
            'position'  => (int) $row['position'],
        ];
    }
}
