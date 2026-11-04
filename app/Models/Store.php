<?php

namespace App\Models;

use Database\Factories\StoreFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $code
 * @property string $timezone
 * @property string $currency
 * @property string $currency_symbol_style
 * @property string|null $locale
 * @property string|null $address_street
 * @property string|null $address_city
 * @property string|null $address_postal
 * @property string|null $address_country
 * @property string|null $phone
 * @property string|null $email
 * @property bool $is_active
 * @property int $week_start_day
 * @property int|null $low_staff_hour_start
 * @property int|null $low_staff_hour_end
 * @property int $min_staff_per_day
 * @property int $min_shift_minutes
 * @property int $max_shift_minutes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, User> $members
 * @property-read Collection<int, StoreFeature> $features
 * @property-read Collection<int, ShiftType> $shiftTypes
 * @property-read Collection<int, Shift> $shifts
 */
#[Fillable([
    'name', 'code', 'timezone', 'currency', 'currency_symbol_style', 'locale',
    'address_street', 'address_city', 'address_postal', 'address_country', 'phone', 'email',
    'is_active', 'week_start_day', 'low_staff_hour_start', 'low_staff_hour_end',
    'min_staff_per_day', 'min_shift_minutes', 'max_shift_minutes',
])]
class Store extends Model
{
    /** @use HasFactory<StoreFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'week_start_day' => 'integer',
            'low_staff_hour_start' => 'integer',
            'low_staff_hour_end' => 'integer',
            'min_staff_per_day' => 'integer',
            'min_shift_minutes' => 'integer',
            'max_shift_minutes' => 'integer',
        ];
    }

    /**
     * @return BelongsToMany<User, $this, StoreMembership, 'pivot'>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'store_user')
            ->using(StoreMembership::class)
            ->withPivot(['id', 'staff_code', 'hire_date', 'contract_type', 'is_active'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<StoreFeature, $this>
     */
    public function features(): HasMany
    {
        return $this->hasMany(StoreFeature::class);
    }

    /**
     * @return BelongsToMany<ShiftType, $this>
     */
    public function shiftTypes(): BelongsToMany
    {
        return $this->belongsToMany(ShiftType::class, 'shift_type_store');
    }

    /**
     * @return HasMany<Shift, $this>
     */
    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class);
    }
}
