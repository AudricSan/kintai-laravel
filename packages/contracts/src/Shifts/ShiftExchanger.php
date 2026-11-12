<?php

namespace Kintai\Contracts\Shifts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Opération « échanger » du Core : deux shifts, tenus par deux employés différents, changent de titulaire en une seule
 * opération atomique. Chacun est remplacé (voir `ShiftReplacer`) par un nouveau shift de même créneau qui appartient à
 * l'autre employé : les anciens partent à la corbeille, reliés à leur remplaçant.
 *
 * Le Core, pas le bundle, applique les garde-fous du planning aux deux affectations à la fois (congé approuvé :
 * bloquant ; chevauchement, indisponibilité, durée : avertissements, contournables avec `$force`), en tenant compte
 * de l'échange lui-même (les deux anciens shifts ne comptent pas dans les chevauchements). Le taux horaire et les
 * minutes nettes imposés d'un shift ne suivent pas : ils sont propres à l'employé.
 */
interface ShiftExchanger
{
    /**
     * @param  Authenticatable  $actor  Doit pouvoir modifier et créer des shifts dans les magasins concernés (droits
     *                                  `shifts.update` et `shifts.create`).
     * @param  int  $shiftId  Premier shift (actif, avec employé).
     * @param  int  $otherShiftId  Second shift (actif, tenu par un autre employé).
     * @param  bool  $force  Ignorer les avertissements du planning (comme le formulaire de shift).
     * @return array{0: int, 1: int} Identifiants des remplaçants : celui de `$shiftId` (désormais à l'autre employé),
     *                               puis celui de `$otherShiftId`.
     *
     * Lève une `AuthorizationException` si un droit manque, une `ValidationException` si un shift est supprimé, déjà
     * remplacé, sans employé ou si les deux employés sont les mêmes, ou si un congé approuvé bloque, et une exception
     * qui implémente `ShiftWarnings` et liste tous les avertissements tant que `$force` est faux. Rien n'est écrit
     * dans ces cas.
     */
    public function exchange(Authenticatable $actor, int $shiftId, int $otherShiftId, bool $force = false): array;
}
