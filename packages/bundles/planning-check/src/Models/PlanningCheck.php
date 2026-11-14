<?php

namespace Kintai\Bundles\PlanningCheck\Models;

use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * La validation du planning d'un magasin pour un jour (une ligne par magasin et par jour visé).
 *
 * @property int $id
 * @property int $store_id
 * @property Carbon $target_date
 * @property string $status
 * @property bool $confirmed_empty
 * @property int|null $submitted_by
 * @property Carbon|null $submitted_at
 * @property int|null $validated_by
 * @property Carbon|null $validated_at
 * @property int|null $rejected_by
 * @property Carbon|null $rejected_at
 * @property string|null $reject_reason
 * @property string $send_status
 * @property Carbon|null $sent_at
 * @property string|null $last_error
 * @property string $shifts_hash
 * @property Carbon|null $escalated_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Store $store
 * @property-read User|null $submitter
 * @property-read User|null $validator
 * @property-read User|null $rejecter
 */
#[Fillable(['store_id', 'target_date', 'status', 'confirmed_empty', 'submitted_by', 'submitted_at', 'validated_by', 'validated_at', 'rejected_by', 'rejected_at', 'reject_reason', 'send_status', 'sent_at', 'last_error', 'shifts_hash', 'escalated_at'])]
class PlanningCheck extends Model
{
    public const PENDING = 'pending';

    public const SUBMITTED = 'submitted';

    public const VALIDATED = 'validated';

    public const REJECTED = 'rejected';

    public const ESCALATED = 'escalated';

    public const STATUSES = [self::PENDING, self::SUBMITTED, self::VALIDATED, self::REJECTED, self::ESCALATED];

    /** États dans lesquels le gérant peut (re)soumettre. */
    public const OPEN = [self::PENDING, self::REJECTED, self::ESCALATED];

    protected $table = 'planning_checks';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'target_date' => 'date:Y-m-d',
            'confirmed_empty' => 'boolean',
            'submitted_at' => 'datetime',
            'validated_at' => 'datetime',
            'rejected_at' => 'datetime',
            'sent_at' => 'datetime',
            'escalated_at' => 'datetime',
        ];
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
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    /**
     * @return HasMany<PlanningCheckSend, $this>
     */
    public function sends(): HasMany
    {
        return $this->hasMany(PlanningCheckSend::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::SUBMITTED => 'To validate',
            self::VALIDATED => 'Validated',
            self::REJECTED => 'Refused',
            self::ESCALATED => 'Late',
            default => 'Pending',
        };
    }
}
