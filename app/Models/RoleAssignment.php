<?php

declare(strict_types=1);

namespace App\Models;

use App\Database\RequestMemo;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\FlushesRequestMemo;

final class RoleAssignment extends Model
{
    use FlushesRequestMemo;

    protected $table = 'role_assignments';
    protected $guarded = [];
    public $timestamps = false;

    public static function findById(int $id): ?array
    {
        $row = static::find($id);
        return $row ? $row->toArray() : null;
    }

    public static function findByUser(int $userId): array
    {
        // Lu à chaque contrôle de droit : une fois par requête (voir RequestMemo).
        return RequestMemo::remember('role_assignments.user.' . $userId, static fn(): array => static::where('user_id', $userId)->get()->toArray());
    }

    public static function findByRole(int $roleId): array
    {
        return static::where('role_id', $roleId)->get()->toArray();
    }

    public static function findByScope(string $scopeType, ?int $scopeId): array
    {
        return static::where('scope_type', $scopeType)
            ->where('scope_id', $scopeId)
            ->get()
            ->toArray();
    }

    public static function assign(int $userId, int $roleId, string $scopeType, ?int $scopeId): ?array
    {
        $exists = static::where('user_id', $userId)
            ->where('role_id', $roleId)
            ->where('scope_type', $scopeType)
            ->where('scope_id', $scopeId)
            ->exists();
        if ($exists) {
            return null;
        }

        $row = static::create([
            'user_id'    => $userId,
            'role_id'    => $roleId,
            'scope_type' => $scopeType,
            'scope_id'   => $scopeId,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return $row->toArray();
    }

    public static function revoke(int $id): int
    {
        $row = static::find($id);
        if ($row) {
            return $row->delete() ? 1 : 0;
        }
        return 0;
    }
}
