<?php

declare(strict_types=1);

namespace App\Models;

use App\Database\RequestMemo;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\FlushesRequestMemo;
use App\Models\Casts\HexColor;

final class Role extends Model
{
    use FlushesRequestMemo;

    protected $table = 'roles';
    protected $guarded = [];
    public $timestamps = false;

    // Une couleur invalide en base est remplacée à la lecture (voir HexColor).
    protected $casts = ['color' => HexColor::class];

    public static function findAll(): array
    {
        return static::all()->toArray();
    }

    public static function findById(int $id): ?array
    {
        // Lu à chaque contrôle de droit : une fois par requête (voir RequestMemo).
        return RequestMemo::remember('roles.' . $id, static function () use ($id): ?array {
            $role = static::find($id);
            return $role ? $role->toArray() : null;
        });
    }

    public static function findBySlug(string $slug): ?array
    {
        $role = static::where('slug', $slug)->first();
        return $role ? $role->toArray() : null;
    }

    public static function put(array $data): array
    {
        if (!empty($data['id'])) {
            $role = static::findOrFail($data['id']);
            $role->fill($data);
            $role->updated_at = date('Y-m-d H:i:s');
            $role->save();
        } else {
            $data['created_at'] = date('Y-m-d H:i:s');
            $role = static::create($data);
        }
        return $role->toArray();
    }

    public static function remove(int $id): int
    {
        $role = static::find($id);
        if ($role === null) {
            return 0;
        }
        RolePermission::where('role_id', $id)->delete();
        RoleAssignment::where('role_id', $id)->delete();
        // Suppressions en masse, sans passer par les modèles : la mémoire de la requête est vidée ici.
        RequestMemo::flush();
        return $role->delete() ? 1 : 0;
    }

    public static function getPermissions(int $roleId): array
    {
        return RequestMemo::remember('role_permissions.' . $roleId, static fn(): array => RolePermission::where('role_id', $roleId)
            ->pluck('permission_key')
            ->toArray());
    }

    public static function getGlobalPermissionKeys(int $roleId): array
    {
        return RequestMemo::remember('role_permissions.global.' . $roleId, static fn(): array => RolePermission::where('role_id', $roleId)
            ->where('scope', 'global')
            ->pluck('permission_key')
            ->toArray());
    }

    public static function savePermissions(int $roleId, array $permissionKeys, array $globalScopeKeys = []): void
    {
        RolePermission::where('role_id', $roleId)->delete();
        RequestMemo::flush();
        foreach ($permissionKeys as $key) {
            RolePermission::create([
                'role_id'        => $roleId,
                'permission_key' => $key,
                'scope'          => in_array($key, $globalScopeKeys, true) ? 'global' : 'local',
            ]);
        }
    }
}
