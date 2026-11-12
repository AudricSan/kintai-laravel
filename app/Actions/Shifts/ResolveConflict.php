<?php

namespace App\Actions\Shifts;

use App\Models\Shift;
use App\Models\User;
use App\Planning\ShiftConflictFinder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Résout un conflit en gardant le shift le plus récent (identifiant le plus élevé) : l'autre est remplacé par lui.
 */
class ResolveConflict
{
    public function __construct(
        private readonly ReplaceShift $replace,
        private readonly ShiftConflictFinder $finder,
    ) {}

    /**
     * @return Shift|null Le shift conservé ; null si l'un des deux a déjà disparu (il n'y a plus rien à résoudre).
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, Shift $a, Shift $b): ?Shift
    {
        $a->refresh();
        $b->refresh();

        if ($a->trashed() || $b->trashed()) {
            return null;
        }

        // Seul un vrai conflit se résout : sinon cette action servirait à supprimer n'importe quel shift.
        if ($a->is($b) || ! $this->finder->overlaps($a, $b)) {
            throw ValidationException::withMessages(['shift' => __('These shifts do not overlap.')]);
        }

        [$older, $newer] = $a->id < $b->id ? [$a, $b] : [$b, $a];

        return $this->replace->handle($actor, $older, $newer);
    }
}
