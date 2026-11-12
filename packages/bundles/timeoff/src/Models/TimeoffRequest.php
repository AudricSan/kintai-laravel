<?php

namespace Kintai\Bundles\Timeoff\Models;

use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Une demande de congé (ou un congé saisi par un gérant, approuvé d'emblée).
 *
 * @property int $id
 * @property int $user_id
 * @property int $store_id
 * @property Carbon $start_date
 * @property Carbon $end_date
 * @property string $type
 * @property string $status
 * @property string|null $reason
 * @property string|null $admin_note
 * @property int|null $processed_by
 * @property Carbon|null $processed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Store $store
 * @property-read User|null $processor
 */
#[Fillable(['user_id', 'store_id', 'start_date', 'end_date', 'type', 'status', 'reason', 'admin_note', 'processed_by', 'processed_at'])]
class TimeoffRequest extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REFUSED = 'refused';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [self::PENDING, self::APPROVED, self::REFUSED, self::CANCELLED];

    public const TYPES = ['vacation', 'sick', 'personal', 'unpaid', 'other'];

    protected $table = 'timeoff_requests';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d', 'processed_at' => 'datetime'];
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
    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    /**
     * Clé de traduction du type.
     */
    public static function typeLabel(string $type): string
    {
        return match ($type) {
            'vacation' => 'Vacation',
            'sick' => 'Sick leave',
            'personal' => 'Personal leave',
            'unpaid' => 'Unpaid leave',
            default => 'Other',
        };
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::APPROVED => 'Approved',
            self::REFUSED => 'Refused',
            self::CANCELLED => 'Cancelled',
            default => 'Pending',
        };
    }
}
