<?php

namespace App\Models;

use App\Casts\TimeOfDay;
use Database\Factories\AvailabilityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;

/**
 * @property int $id
 * @property int $user_id
 * @property int $store_id
 * @property int $day_of_week ISO : 1 (lundi) à 7 (dimanche)
 * @property string|null $starts_at Heure "H:i"
 * @property string|null $ends_at Heure "H:i"
 * @property bool $is_available
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Store $store
 */
#[Fillable(['user_id', 'store_id', 'day_of_week', 'starts_at', 'ends_at', 'is_available'])]
class Availability extends Model
{
    /** @use HasFactory<AvailabilityFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'starts_at' => TimeOfDay::class,
            'ends_at' => TimeOfDay::class,
            'is_available' => 'boolean',
        ];
    }

    /**
     * Jour ISO (1 = lundi, 7 = dimanche) d'une date.
     */
    public static function isoDayOf(\DateTimeInterface|string $date): int
    {
        return Date::parse($date)->dayOfWeekIso;
    }

    /**
     * Disponibilités correspondant au jour de la semaine d'une date.
     *
     * @param  Builder<Availability>  $query
     */
    public function scopeOnDay(Builder $query, \DateTimeInterface|string $date): void
    {
        $query->where('day_of_week', self::isoDayOf($date));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
