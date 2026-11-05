<?php

namespace Kintai\Contracts\Shifts;

use Throwable;

/**
 * Exception levée par les opérations sur les shifts du Core (`ShiftReplacer`, `ShiftExchanger`) quand le planning
 * relève des avertissements et que l'appelant n'a pas confirmé (`$force`). Rien n'est écrit : le bundle montre les
 * messages à l'utilisateur, puis relance l'opération avec `$force` à vrai s'il confirme.
 *
 * L'API du Core rend elle-même cette exception (409 `shift_warnings`) : un contrôleur d'API de bundle la laisse passer.
 */
interface ShiftWarnings extends Throwable
{
    /**
     * Les avertissements, déjà traduits.
     *
     * @return list<string>
     */
    public function messages(): array;
}
