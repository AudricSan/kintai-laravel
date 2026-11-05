<?php

namespace Kintai\Bundles\Timeclock\Models;

use App\Models\Shift;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un pointage : une entrée, puis une sortie (absente tant que l'employé est en poste).
 *
 * @property int $id
 * @property int $user_id
 * @property int $store_id
 * @property int|null $shift_id
 * @property CarbonInterface $shift_date Jour local (fuseau de l'instance) de l'entrée.
 * @property CarbonInterface $clock_in_at UTC
 * @property CarbonInterface|null $clock_out_at UTC
 * @property int|null $duration_minutes
 * @property string|null $clock_in_ip
 * @property string|null $clock_out_ip
 * @property string|null $notes
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read User $user
 * @property-read Store $store
 * @property-read Shift|null $shift
 */
#[Fillable(['user_id', 'store_id', 'shift_id', 'shift_date', 'clock_in_at', 'clock_out_at', 'duration_minutes', 'clock_in_ip', 'clock_out_ip', 'notes'])]
class Timeclock extends Model
{
    protected $table = 'timeclocks';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'shift_date' => 'date:Y-m-d',
            'clock_in_at' => 'datetime',
            'clock_out_at' => 'datetime',
            'duration_minutes' => 'integer',
        ];
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

    /**
     * @return BelongsTo<Shift, $this>
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function isActive(): bool
    {
        return $this->clock_out_at === null;
    }

    public function localIn(): CarbonInterface
    {
        return $this->clock_in_at->copy()->timezone(self::timezone());
    }

    public function localOut(): ?CarbonInterface
    {
        return $this->clock_out_at?->copy()->timezone(self::timezone());
    }

    /**
     * Retard à l'entrée en minutes si le pointage est rattaché à un shift et dépasse le seuil ; sinon null.
     */
    public function lateMinutes(int $threshold): ?int
    {
        if ($this->shift === null) {
            return null;
        }

        $late = (int) floor($this->shift->starts_at->diffInSeconds($this->clock_in_at, false) / 60);

        return $late > $threshold ? $late : null;
    }

    public static function timezone(): string
    {
        return (string) config('kintai.timezone', 'UTC');
    }
}
