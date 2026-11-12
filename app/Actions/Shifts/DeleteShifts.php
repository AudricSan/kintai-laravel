<?php

namespace App\Actions\Shifts;

use App\Authorization\Authorizer;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Suppression groupée : le droit est contrôlé pour chaque shift, avant toute suppression (tout ou rien).
 */
class DeleteShifts
{
    public function __construct(private readonly DeleteShift $delete, private readonly Authorizer $authorizer) {}

    /**
     * Les identifiants inconnus ou déjà supprimés sont ignorés.
     *
     * @param  list<int>  $ids
     * @return int Nombre de shifts supprimés.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, array $ids): int
    {
        $shifts = Shift::query()->whereKey(array_unique($ids))->get();

        // Même droit que DeleteShift, vérifié d'abord pour ne rien supprimer si un seul shift est interdit.
        foreach ($shifts as $shift) {
            if (! $this->authorizer->allows($actor, 'shifts.delete', $shift->store_id)) {
                throw new AuthorizationException;
            }
        }

        DB::transaction(function () use ($actor, $shifts): void {
            foreach ($shifts as $shift) {
                $this->delete->handle($actor, $shift);
            }
        });

        return $shifts->count();
    }
}
