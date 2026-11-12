<?php

namespace App\Models;

use Database\Factories\DashboardLayoutFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Disposition d'un tableau de bord : personnelle (`user_id`) ou imposée à un rôle (`role_id`).
 *
 * @property int $id
 * @property int|null $user_id
 * @property int|null $role_id
 * @property string $dashboard `manager` ou `employee`
 * @property string $name
 * @property list<array{widget: string, size?: string|null}> $layout Widgets dans l'ordre d'affichage
 * @property bool $is_active
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 * @property-read Role|null $role
 */
#[Fillable(['user_id', 'role_id', 'dashboard', 'name', 'layout', 'is_active', 'position'])]
class DashboardLayout extends Model
{
    /** @use HasFactory<DashboardLayoutFactory> */
    use HasFactory;

    public const MANAGER = 'manager';

    public const EMPLOYEE = 'employee';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'layout' => 'array',
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }

    /**
     * @param  Builder<DashboardLayout>  $query
     */
    public function scopePersonal(Builder $query, User $user, string $dashboard): void
    {
        $query->where('user_id', $user->id)->where('dashboard', $dashboard);
    }

    /**
     * @param  Builder<DashboardLayout>  $query
     */
    public function scopeForRole(Builder $query, Role $role, string $dashboard): void
    {
        $query->whereNull('user_id')->where('role_id', $role->id)->where('dashboard', $dashboard);
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
}
