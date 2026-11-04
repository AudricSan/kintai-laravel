<?php

namespace App\Models;

use App\Casts\TimeOfDay;
use Database\Factories\ShiftTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $code
 * @property string $starts_at Heure "H:i"
 * @property string $ends_at Heure "H:i"
 * @property numeric-string $hourly_rate
 * @property string $color
 * @property int $sort_order
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Store> $stores
 * @property-read Collection<int, User> $rates
 */
#[Fillable(['name', 'code', 'starts_at', 'ends_at', 'hourly_rate', 'color', 'sort_order', 'is_active'])]
class ShiftType extends Model
{
    /** @use HasFactory<ShiftTypeFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => TimeOfDay::class,
            'ends_at' => TimeOfDay::class,
            'hourly_rate' => 'decimal:2',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsToMany<Store, $this>
     */
    public function stores(): BelongsToMany
    {
        return $this->belongsToMany(Store::class, 'shift_type_store');
    }

    /**
     * Utilisateurs ayant un taux horaire personnel pour ce type (pivot `hourly_rate`).
     *
     * @return BelongsToMany<User, $this>
     */
    public function rates(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'shift_type_user')
            ->withPivot('hourly_rate')
            ->withTimestamps();
    }
}
