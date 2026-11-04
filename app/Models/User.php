<?php

namespace App\Models;

use App\Casts\TimeOfDay;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string|null $furigana_first_name
 * @property string|null $furigana_last_name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string|null $employee_code
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property string|null $locale
 * @property string $color
 * @property string|null $avatar_path
 * @property bool $is_active
 * @property string|null $quiet_hours_start Heure "H:i"
 * @property string|null $quiet_hours_end Heure "H:i"
 * @property bool $share_email
 * @property bool $share_phone
 * @property bool $share_mobile_phone
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read EmployeeProfile|null $profile
 * @property-read Collection<int, Role> $roles
 * @property-read Collection<int, Store> $stores
 * @property-read Collection<int, Shift> $shifts
 * @property-read Collection<int, Availability> $availabilities
 * @property-read Collection<int, ShiftType> $personalRates
 */
#[Fillable([
    'name', 'first_name', 'last_name', 'furigana_first_name', 'furigana_last_name', 'email',
    'employee_code', 'password', 'locale', 'color', 'avatar_path', 'is_active',
    'quiet_hours_start', 'quiet_hours_end', 'share_email', 'share_phone', 'share_mobile_phone',
])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, SoftDeletes, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'share_email' => 'boolean',
            'share_phone' => 'boolean',
            'share_mobile_phone' => 'boolean',
            'quiet_hours_start' => TimeOfDay::class,
            'quiet_hours_end' => TimeOfDay::class,
        ];
    }

    /**
     * @return HasOne<EmployeeProfile, $this>
     */
    public function profile(): HasOne
    {
        return $this->hasOne(EmployeeProfile::class);
    }

    /**
     * Rôles attribués ; le pivot indique le magasin (store_id null = global).
     *
     * @return BelongsToMany<Role, $this, RoleAssignment, 'pivot'>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_user')
            ->using(RoleAssignment::class)
            ->withPivot(['id', 'store_id', 'created_at']);
    }

    /**
     * @return BelongsToMany<Store, $this, StoreMembership, 'pivot'>
     */
    public function stores(): BelongsToMany
    {
        return $this->belongsToMany(Store::class, 'store_user')
            ->using(StoreMembership::class)
            ->withPivot(['id', 'staff_code', 'hire_date', 'contract_type', 'is_active'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<Shift, $this>
     */
    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class);
    }

    /**
     * @return HasMany<Availability, $this>
     */
    public function availabilities(): HasMany
    {
        return $this->hasMany(Availability::class);
    }

    /**
     * Types de shift pour lesquels l'utilisateur a un taux horaire personnel (pivot `hourly_rate`).
     *
     * @return BelongsToMany<ShiftType, $this>
     */
    public function personalRates(): BelongsToMany
    {
        return $this->belongsToMany(ShiftType::class, 'shift_type_user')
            ->withPivot('hourly_rate')
            ->withTimestamps();
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }
}
