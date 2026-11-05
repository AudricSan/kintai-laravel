<?php

namespace Kintai\Bundles\ShiftSwap\Models;

use App\Models\Shift;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Une demande d'échange : le demandeur propose l'un de ses shifts contre un shift d'un collègue. Le collègue répond,
 * puis un gérant valide ; le Core applique l'échange (voir `SwapActions`).
 *
 * @property int $id
 * @property int $store_id
 * @property int $requester_id
 * @property int $target_user_id
 * @property int $requester_shift_id
 * @property int $target_shift_id
 * @property int $requester_shift_sequence
 * @property int $target_shift_sequence
 * @property string $status
 * @property string|null $reason
 * @property Carbon|null $accepted_at
 * @property int|null $approved_by_id
 * @property Carbon|null $approved_at
 * @property int|null $applied_requester_shift_id
 * @property int|null $applied_target_shift_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Store $store
 * @property-read User $requester
 * @property-read User $target
 * @property-read User|null $approver
 * @property-read Shift|null $requesterShift
 * @property-read Shift|null $targetShift
 */
#[Fillable([
    'store_id', 'requester_id', 'target_user_id', 'requester_shift_id', 'target_shift_id', 'requester_shift_sequence',
    'target_shift_sequence', 'status', 'reason', 'accepted_at', 'approved_by_id', 'approved_at',
    'applied_requester_shift_id', 'applied_target_shift_id',
])]
class ShiftSwapRequest extends Model
{
    public const PENDING = 'pending';

    /** Échange appliqué au planning. */
    public const ACCEPTED = 'accepted';

    public const REFUSED = 'refused';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [self::PENDING, self::ACCEPTED, self::REFUSED, self::CANCELLED];

    protected $table = 'shift_swap_requests';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'requester_shift_sequence' => 'integer', 'target_shift_sequence' => 'integer',
            'accepted_at' => 'datetime', 'approved_at' => 'datetime',
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
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function target(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    /**
     * Les shifts restent visibles même après l'échange (ils sont alors dans la corbeille).
     *
     * @return BelongsTo<Shift, $this>
     */
    public function requesterShift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'requester_shift_id')->withTrashed();
    }

    /**
     * @return BelongsTo<Shift, $this>
     */
    public function targetShift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'target_shift_id')->withTrashed();
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    /** En attente de la réponse du collègue. */
    public function awaitsColleague(): bool
    {
        return $this->isPending() && $this->accepted_at === null;
    }

    /** Le collègue a accepté : en attente de la décision d'un gérant. */
    public function awaitsManager(): bool
    {
        return $this->isPending() && $this->accepted_at !== null;
    }

    /**
     * Étape lisible : `waiting_colleague`, `waiting_manager`, ou le statut final.
     */
    public function stage(): string
    {
        return match (true) {
            $this->awaitsColleague() => 'waiting_colleague',
            $this->awaitsManager() => 'waiting_manager',
            default => $this->status,
        };
    }

    /**
     * Clé de traduction de l'étape.
     */
    public static function stageLabel(string $stage): string
    {
        return match ($stage) {
            'waiting_colleague' => 'Waiting for colleague',
            'waiting_manager' => 'Waiting for manager',
            self::ACCEPTED => 'Applied',
            self::REFUSED => 'Refused',
            self::CANCELLED => 'Cancelled',
            default => 'Pending',
        };
    }

    /**
     * Couleur du badge d'une étape.
     */
    public static function stageColor(string $stage): string
    {
        return match ($stage) {
            'waiting_colleague' => 'amber',
            'waiting_manager' => 'blue',
            self::ACCEPTED => 'green',
            self::REFUSED => 'red',
            default => 'zinc',
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
