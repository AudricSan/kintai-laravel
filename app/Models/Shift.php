<?php

namespace App\Models;

use App\Exceptions\ShiftRestoreException;
use Carbon\CarbonInterface;
use Database\Factories\ShiftFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $store_id
 * @property int|null $user_id null = shift ouvert
 * @property int|null $shift_type_id
 * @property Carbon $shift_date Jour local de rattachement
 * @property Carbon $starts_at Stocké en UTC
 * @property Carbon $ends_at Stocké en UTC
 * @property int $pause_minutes
 * @property string|null $notes
 * @property numeric-string|null $hourly_rate_override
 * @property int|null $net_minutes_override
 * @property string $ical_uid
 * @property int $ical_sequence
 * @property int|null $replaced_by_id
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read int $durationMinutes
 * @property-read bool $crossesMidnight
 * @property-read CarbonInterface $localStartsAt
 * @property-read CarbonInterface $localEndsAt
 * @property-read int $netMinutes
 * @property-read Store $store
 * @property-read User|null $user
 * @property-read ShiftType|null $shiftType
 * @property-read User|null $creator
 * @property-read Shift|null $replacedBy
 * @property-read Shift|null $replaces
 */
#[Fillable([
    'store_id', 'user_id', 'shift_type_id', 'shift_date', 'starts_at', 'ends_at', 'pause_minutes',
    'notes', 'hourly_rate_override', 'net_minutes_override', 'ical_uid', 'ical_sequence',
    'replaced_by_id', 'created_by',
])]
class Shift extends Model
{
    /** @use HasFactory<ShiftFactory> */
    use HasFactory, SoftDeletes;

    /** @var array<string, mixed> */
    protected $attributes = ['pause_minutes' => 0, 'ical_sequence' => 0];

    protected static function booted(): void
    {
        static::creating(function (Shift $shift): void {
            if (empty($shift->ical_uid)) {
                $shift->ical_uid = (string) Str::uuid();
            }
        });

        static::updating(function (Shift $shift): void {
            $shift->ical_sequence++;
        });

        static::restoring(function (Shift $shift): void {
            $replacement = $shift->replacedBy;
            if ($replacement !== null) {
                throw ShiftRestoreException::replacedByActiveShift($shift->id, $replacement->id);
            }

            // Le remplaçant est dans la corbeille : le lien n'a plus lieu d'être.
            $shift->replaced_by_id = null;
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'shift_date' => 'date:Y-m-d',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'pause_minutes' => 'integer',
            'hourly_rate_override' => 'decimal:2',
            'net_minutes_override' => 'integer',
            'ical_sequence' => 'integer',
        ];
    }

    /**
     * Durée réelle en minutes (écart entre les deux instants, donc exacte les jours de changement d'heure).
     *
     * @return Attribute<int, never>
     */
    protected function durationMinutes(): Attribute
    {
        return Attribute::get(
            fn (): int => intdiv($this->ends_at->getTimestamp() - $this->starts_at->getTimestamp(), 60)
        );
    }

    /**
     * @return Attribute<bool, never>
     */
    protected function crossesMidnight(): Attribute
    {
        return Attribute::get(
            fn (): bool => $this->localStartsAt->toDateString() !== $this->localEndsAt->toDateString()
        );
    }

    /**
     * Début dans le fuseau de l'instance (`kintai.timezone`).
     *
     * @return Attribute<Carbon, never>
     */
    protected function localStartsAt(): Attribute
    {
        return Attribute::get(fn (): CarbonInterface => $this->starts_at->copy()->setTimezone(self::instanceTimezone()));
    }

    /**
     * Fin dans le fuseau de l'instance (`kintai.timezone`).
     *
     * @return Attribute<Carbon, never>
     */
    protected function localEndsAt(): Attribute
    {
        return Attribute::get(fn (): CarbonInterface => $this->ends_at->copy()->setTimezone(self::instanceTimezone()));
    }

    /**
     * Minutes payées : surcharge manuelle, sinon durée moins la pause (plancher 0).
     *
     * @return Attribute<int<0, max>, never>
     */
    protected function netMinutes(): Attribute
    {
        return Attribute::get(function (): int {
            if ($this->net_minutes_override !== null) {
                return max(0, $this->net_minutes_override);
            }

            return max(0, $this->durationMinutes - $this->pause_minutes);
        });
    }

    /**
     * Shifts ouverts (sans employé).
     *
     * @param  Builder<Shift>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('user_id');
    }

    /**
     * @param  Builder<Shift>  $query
     */
    public function scopeForStore(Builder $query, Store|int $store): void
    {
        $query->where('store_id', $store instanceof Store ? $store->id : $store);
    }

    /**
     * Shifts rattachés à un jour compris entre deux dates incluses.
     *
     * @param  Builder<Shift>  $query
     */
    public function scopeBetween(Builder $query, \DateTimeInterface|string $from, \DateTimeInterface|string $to): void
    {
        $query->whereBetween('shift_date', [
            Carbon::parse($from)->toDateString(),
            Carbon::parse($to)->toDateString(),
        ]);
    }

    /**
     * Construit (sans l'enregistrer) un shift à partir d'heures locales du fuseau de l'instance
     * (`kintai.timezone`) : jour local de rattachement, début et fin "H:i". La fin tombe le lendemain
     * si elle n'est pas postérieure au début. Les horodatages sont convertis en UTC.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function fromLocalTimes(string $localDate, string $start, string $end, array $attributes = []): self
    {
        $startsAt = static::localTime($localDate, $start);
        $endsAt = static::localTime($localDate, $end);
        if ($endsAt->lessThanOrEqualTo($startsAt)) {
            $endsAt = static::localTime(Carbon::parse($localDate)->addDay()->toDateString(), $end);
        }

        return new self($attributes + [
            'shift_date' => $localDate,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]);
    }

    /**
     * Instant (en UTC) d'une date et d'une heure locales du fuseau de l'instance.
     */
    public static function localTime(string $localDate, string $time): Carbon
    {
        return Carbon::parse($localDate.' '.$time, self::instanceTimezone())->utc();
    }

    private static function instanceTimezone(): string
    {
        return (string) config('kintai.timezone', 'UTC');
    }

    /**
     * Remplace ce shift par `$new` : l'ancien part à la corbeille avec `replaced_by_id`.
     *
     * - `$new` pas encore enregistré : il reprend l'`ical_uid` de l'ancien avec une séquence + 1
     *   (le calendrier voit une modification) ; l'ancien reçoit un uid de substitution.
     * - `$new` déjà enregistré (donc déjà publié dans les flux iCal) : personne ne change d'uid ; l'ancien,
     *   supprimé, sera publié comme annulé et `$new` n'est pas modifié.
     */
    public function replaceWith(Shift $new): void
    {
        DB::transaction(function () use ($new): void {
            if (! $new->exists) {
                $uid = $this->ical_uid;

                // L'identifiant est unique : on libère celui de l'ancien shift avant de le donner au nouveau.
                $this->forceFill(['ical_uid' => (string) Str::uuid()])->saveQuietly();

                $new->ical_uid = $uid;
                $new->ical_sequence = $this->ical_sequence + 1;
                $new->saveQuietly();
            }

            $this->forceFill(['replaced_by_id' => $new->id])->saveQuietly();
            $this->delete();
        });
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<ShiftType, $this>
     */
    public function shiftType(): BelongsTo
    {
        return $this->belongsTo(ShiftType::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Le shift qui a remplacé celui-ci (hors corbeille).
     *
     * @return BelongsTo<Shift, $this>
     */
    public function replacedBy(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'replaced_by_id');
    }

    /**
     * Le shift que celui-ci a remplacé (dans la corbeille).
     *
     * @return HasOne<Shift, $this>
     */
    public function replaces(): HasOne
    {
        return $this->hasOne(Shift::class, 'replaced_by_id')->withTrashed();
    }
}
