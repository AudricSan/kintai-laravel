<?php

namespace Kintai\Bundles\PlanningCheck\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un envoi du PDF d'un planning validé (le premier envoi, un renvoi ou un nouvel essai après un échec).
 *
 * @property int $id
 * @property int $planning_check_id
 * @property int|null $sent_by
 * @property string $status
 * @property string $shifts_hash
 * @property int $recipient_count
 * @property string|null $error
 * @property Carbon|null $created_at
 * @property-read PlanningCheck $check
 * @property-read User|null $sender
 */
#[Fillable(['planning_check_id', 'sent_by', 'status', 'shifts_hash', 'recipient_count', 'error'])]
class PlanningCheckSend extends Model
{
    protected $table = 'planning_check_sends';

    /**
     * @return BelongsTo<PlanningCheck, $this>
     */
    public function check(): BelongsTo
    {
        return $this->belongsTo(PlanningCheck::class, 'planning_check_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
