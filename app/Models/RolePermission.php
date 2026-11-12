<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Clé primaire composite (role_id, permission) : à écrire via la relation `permissions` du rôle.
 *
 * @property int $role_id
 * @property string $permission
 * @property bool $all_stores
 * @property-read Role $role
 */
#[Fillable(['role_id', 'permission', 'all_stores'])]
class RolePermission extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['all_stores' => 'boolean'];
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
