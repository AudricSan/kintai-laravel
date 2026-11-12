<?php

namespace Kintai\Contracts\Shifts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Opération « remplacer » du Core : l'ancien shift part à la corbeille (relié à son remplaçant) et le remplaçant fait
 * foi. Les bundles qui échangent ou reprennent des shifts (shift-swap, shift-claim, résolution de conflit) l'appellent
 * au lieu d'écrire dans le planning. L'événement `ShiftReplaced` est émis.
 */
interface ShiftReplacer
{
    /**
     * @param  Authenticatable  $actor  Doit pouvoir modifier les shifts concernés (droit `shifts.update` du magasin).
     * @param  int  $shiftId  Shift à remplacer (actif).
     * @param  int|array<string, mixed>  $replacement  Identifiant d'un autre shift actif existant, ou saisie d'un
     *                                                 nouveau shift (champs du formulaire de shift : `store_id`,
     *                                                 `date`, `start`, `end`, `user_id`, `shift_type_id`...), qui
     *                                                 reprend alors l'identifiant d'agenda de l'ancien.
     * @param  bool  $force  Ignorer les avertissements du planning (comme le formulaire de shift).
     * @return int Identifiant du shift remplaçant.
     *
     * Lève, selon le cas (exceptions de Laravel et du Core, hors du contrat) : une `AuthorizationException` si le droit
     * manque, une `ValidationException` si un shift est supprimé ou si le remplaçant ou la saisie est invalide, et
     * une exception du Core qui liste les avertissements du planning tant que `$force` est faux.
     */
    public function replace(Authenticatable $actor, int $shiftId, int|array $replacement, bool $force = false): int;
}
