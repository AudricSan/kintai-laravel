<?php

namespace Kintai\Bundles\ShiftClaim\Models;

use App\Models\Shift;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * La candidature d'un employé à un shift de la bourse (shift ouvert ou proposé par un gérant).
 *
 * @property int $id
 * @property int $shift_id
 * @property int $user_id
 * @property int $store_id
 * @property string $status
 * @property string|null $note
 * @property Carbon|null $resolved_at
 * @property int|null $resolved_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Shift|null $shift
 * @property-read User $user
 * @property-read Store $store
 * @property-read User|null $resolver
 */
#[Fillable(['shift_id', 'user_id', 'store_id', 'status', 'note', 'resolved_at', 'resolved_by'])]
class ShiftClaim extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const WITHDRAWN = 'withdrawn';

    public const STATUSES = [self::PENDING, self::APPROVED, self::REJECTED, self::WITHDRAWN];

    protected $table = 'shift_claims';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    /**
     * Le shift reste lisible même une fois attribué (il est alors dans la corbeille, remplacé).
     *
     * @return BelongsTo<Shift, $this>
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class)->withTrashed();
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
     * @return BelongsTo<User, $this>
     */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::APPROVED => 'Approved',
            self::REJECTED => 'Rejected',
            self::WITHDRAWN => 'Withdrawn',
            default => 'Pending',
        };
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            self::APPROVED => 'green',
            self::REJECTED => 'red',
            self::WITHDRAWN => 'zinc',
            default => 'amber',
        };
    }

    /**
     * Shift en une ligne : « 2026-11-10 09:00-17:00 ».
     */
    public static function describe(?Shift $shift): string
    {
        if ($shift === null) {
            return '-';
        }

        return $shift->shift_date->toDateString().' '.$shift->localStartsAt->format('H:i').'-'.$shift->localEndsAt->format('H:i');
    }
}
