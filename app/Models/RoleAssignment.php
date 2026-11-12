<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * Pivot `role_user` : un rôle attribué à un utilisateur, globalement (store_id null) ou pour un magasin.
 *
 * @property int $id
 * @property int $user_id
 * @property int $role_id
 * @property int|null $store_id
 * @property Carbon|null $created_at
 * @property-read User $user
 * @property-read Role $role
 * @property-read Store|null $store
 */
class RoleAssignment extends Pivot
{
    protected $table = 'role_user';

    public $incrementing = true;

    public function getCreatedAtColumn(): string
    {
        return 'created_at';
    }

    public function getUpdatedAtColumn(): ?string
    {
        return null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
