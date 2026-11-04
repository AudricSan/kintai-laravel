<?php

namespace App\Actions\Shifts;

use App\Authorization\Authorizer;
use App\Events\ShiftReplaced;
use App\Exceptions\ShiftWarningsException;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Opération « remplacer » du Core (docs/SCHEMA-CIBLE.md, section Remplacement) : l'ancien shift part à la corbeille
 * avec `replaced_by_id` et le remplaçant fait foi. Les bundles (échange, reprise, résolution de conflit) l'appellent.
 *
 * - Remplaçant donné en saisie (tableau) : il est créé et reprend l'`ical_uid` de l'ancien, séquence + 1.
 * - Remplaçant déjà existant (Shift) : personne ne change d'`ical_uid`.
 */
class ReplaceShift
{
    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly ShiftPreparer $preparer,
    ) {}

    /**
     * @param  Shift|array<string, mixed>  $replacement  Shift existant, ou saisie d'un nouveau shift (voir ShiftPreparer).
     * @return Shift Le remplaçant.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws ShiftWarningsException
     */
    public function handle(User $actor, Shift $old, Shift|array $replacement, bool $force = false): Shift
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
            $replacement = $this->preparer->prepare($actor, $replacement, null, $force, [$old->id]);
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
