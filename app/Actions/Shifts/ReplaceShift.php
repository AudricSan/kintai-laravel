<?php

namespace App\Actions\Shifts;

use App\Authorization\Authorizer;
use App\Exceptions\ShiftWarningsException;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Validation\ValidationException;
use Kintai\Contracts\Events\ShiftReplaced;
use Kintai\Contracts\Shifts\ShiftReplacer;

/**
 * Opération « remplacer » du Core (docs/SCHEMA-CIBLE.md, section Remplacement) : l'ancien shift part à la corbeille
 * avec `replaced_by_id` et le remplaçant fait foi. Les bundles (échange, reprise, résolution de conflit) l'appellent.
 *
 * - Remplaçant donné en saisie (tableau) : il est créé et reprend l'`ical_uid` de l'ancien, séquence + 1.
 * - Remplaçant déjà existant (Shift) : personne ne change d'`ical_uid`.
 */
class ReplaceShift implements ShiftReplacer
{
    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly ShiftPreparer $preparer,
    ) {}

    /**
     * Point d'entrée des bundles (contrat `ShiftReplacer`) : travaille sur des identifiants.
     */
    public function replace(Authenticatable $actor, int $shiftId, int|array $replacement, bool $force = false): int
    {
        $user = $actor instanceof User ? $actor : User::query()->findOrFail($actor->getAuthIdentifier());
        $old = Shift::query()->withTrashed()->findOrFail($shiftId);
        $replacement = is_int($replacement) ? Shift::query()->withTrashed()->findOrFail($replacement) : $replacement;

        return $this->handle($user, $old, $replacement, $force)->id;
    }

    /**
     * @param  Shift|array<string, mixed>  $replacement  Shift existant, ou saisie d'un nouveau shift (voir ShiftPreparer).
     * @param  list<int>  $ignoreShiftIds  Autres shifts qui disparaissent avec cette opération (échange) : ils ne comptent pas dans les chevauchements.
     * @return Shift Le remplaçant.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws ShiftWarningsException
     */
    public function handle(User $actor, Shift $old, Shift|array $replacement, bool $force = false, array $ignoreShiftIds = []): Shift
    {
        $this->authorize($actor, 'shifts.update', $old->store_id);

        if ($old->trashed()) {
            throw ValidationException::withMessages(['shift' => __('This shift is deleted.')]);
        }

        if ($replacement instanceof Shift) {
            $this->authorize($actor, 'shifts.update', $replacement->store_id);

            if ($replacement->trashed() || $replacement->is($old)) {
                throw ValidationException::withMessages(['shift' => __('The replacement shift must be another active shift.')]);
            }
        } else {
            $replacement = $this->preparer->prepare($actor, $replacement, null, $force, [$old->id, ...$ignoreShiftIds]);
        }

        $old->replaceWith($replacement);

        ShiftReplaced::dispatch($old->id, $replacement->id, $actor->id);

        return $replacement;
    }

    /**
     * @throws AuthorizationException
     */
    private function authorize(User $actor, string $permission, int $storeId): void
    {
        if (! $this->authorizer->allows($actor, $permission, $storeId)) {
            throw new AuthorizationException;
        }
    }
}
