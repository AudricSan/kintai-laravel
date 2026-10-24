<?php

declare(strict_types=1);

namespace kintai\Core\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;

/**
 * Blueprint des migrations Kintai (Core et bundles, via Migration::schema()), identique à celui d'Illuminate sauf pour
 * increments() : un entier auto-incrémenté SIGNÉ au lieu de non signé.
 *
 * Les colonnes qui référencent ces identifiants sont déclarées partout en integer() (signé) avec une clé étrangère.
 * SQLite ne distingue pas signé et non signé ; MySQL refuse la clé étrangère (« Referencing column … and referenced
 * column 'id' … are incompatible ») : une installation neuve sur MySQL échouait dès la deuxième migration. Aligner la
 * clé primaire sur ses références corrige toutes les tables d'un coup, sans réécrire chaque migration. Aucun effet sous
 * SQLite.
 */
final class PortableBlueprint extends Blueprint
{
    public function increments($column): ColumnDefinition
    {
        return $this->integer($column, true, false);
    }
}
