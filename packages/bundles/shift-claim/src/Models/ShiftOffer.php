<?php

namespace Kintai\Bundles\ShiftClaim\Models;

use App\Models\Shift;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un shift attribué que sa gestion propose à la bourse : son titulaire le garde jusqu'à l'approbation d'un candidat.
 * Un shift ouvert (sans employé) est dans la bourse sans qu'aucune proposition ne soit nécessaire.
 *
 * @property int $id
 * @property int $shift_id
 * @property int|null $offered_by
 * @property string|null $note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Shift $shift
 * @property-read User|null $offeredBy
 */
#[Fillable(['shift_id', 'offered_by', 'note'])]
class ShiftOffer extends Model
{
    protected $table = 'shift_claim_offers';

    /**
     * @return BelongsTo<Shift, $this>
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function offeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'offered_by');
    }
}
