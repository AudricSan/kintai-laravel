<?php

declare(strict_types=1);

namespace kintai\Core\Repositories;

use kintai\Domain\Eloquent\Role as EloquentRole;
use kintai\Domain\Eloquent\RolePermission;
use kintai\Domain\Eloquent\RoleAssignment;

final class DatabaseRoleRepository implements RoleRepositoryInterface
{
    public function __construct() {}

    public function findAll(): array
    {
        return EloquentRole::all()->toArray();
    }

    public function findById(int $id): ?array
    {
        $role = EloquentRole::find($id);
        return $role ? $role->toArray() : null;
    }

    public function findBySlug(string $slug): ?array
    {
        $role = EloquentRole::where('slug', $slug)->first();
        return $role ? $role->toArray() : null;
    }

    public function save(array $data): array
    {
        if (!empty($data['id'])) {
            $role = EloquentRole::findOrFail($data['id']);
            $role->fill($data);
            $role->updated_at = date('Y-m-d H:i:s');
            $role->save();
        } else {
            $data['created_at'] = date('Y-m-d H:i:s');
            $role = EloquentRole::create($data);
        }
        return $role->toArray();
    }

    public function delete(int $id): int
    {
        $role = EloquentRole::find($id);
        if ($role === null) {
            return 0;
        }
        RolePermission::where('role_id', $id)->delete();
        RoleAssignment::where('role_id', $id)->delete();
        return $role->delete() ? 1 : 0;
    }

    public function getPermissions(int $roleId): array
    {
        return RolePermission::where('role_id', $roleId)
            ->pluck('permission_key')
            ->toArray();
    }

    public function getGlobalPermissionKeys(int $roleId): array
    {
        return RolePermission::where('role_id', $roleId)
            ->where('scope', 'global')
            ->pluck('permission_key')
            ->toArray();
    }

    public function savePermissions(int $roleId, array $permissionKeys, array $globalScopeKeys = []): void
    {
        RolePermission::where('role_id', $roleId)->delete();
        foreach ($permissionKeys as $key) {
            RolePermission::create([
                'role_id'        => $roleId,
                'permission_key' => $key,
                'scope'          => in_array($key, $globalScopeKeys, true) ? 'global' : 'local',
            ]);
        }
    }
}
