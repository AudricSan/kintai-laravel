<?php

declare(strict_types=1);

namespace kintai\Core\Repositories;

use kintai\Domain\Eloquent\RoleAssignment as EloquentRoleAssignment;

final class DatabaseRoleAssignmentRepository implements RoleAssignmentRepositoryInterface
{
    public function __construct() {}

    public function findById(int $id): ?array
    {
        $row = EloquentRoleAssignment::find($id);
        return $row ? $row->toArray() : null;
    }

    public function findByUser(int $userId): array
    {
        return EloquentRoleAssignment::where('user_id', $userId)->get()->toArray();
    }

    public function findByRole(int $roleId): array
    {
        return EloquentRoleAssignment::where('role_id', $roleId)->get()->toArray();
    }

    public function findByScope(string $scopeType, ?int $scopeId): array
    {
        return EloquentRoleAssignment::where('scope_type', $scopeType)
            ->where('scope_id', $scopeId)
            ->get()
            ->toArray();
    }

    public function assign(int $userId, int $roleId, string $scopeType, ?int $scopeId): ?array
    {
        $exists = EloquentRoleAssignment::where('user_id', $userId)
            ->where('role_id', $roleId)
            ->where('scope_type', $scopeType)
            ->where('scope_id', $scopeId)
            ->exists();
        if ($exists) {
            return null;
        }

        $row = EloquentRoleAssignment::create([
            'user_id'    => $userId,
            'role_id'    => $roleId,
            'scope_type' => $scopeType,
            'scope_id'   => $scopeId,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return $row->toArray();
    }

    public function revoke(int $id): int
    {
        $row = EloquentRoleAssignment::find($id);
        if ($row) {
            return $row->delete() ? 1 : 0;
        }
        return 0;
    }
}
