<?php

namespace Kintai\Bundles\PlanningImport\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Nom lu dans un fichier d'import (en minuscules) rattaché à un employé du magasin.
 *
 * @property int $id
 * @property int $store_id
 * @property string $staff_name
 * @property int $user_id
 */
#[Fillable(['store_id', 'staff_name', 'user_id'])]
class ImportAlias extends Model
{
    protected $table = 'import_aliases';
}
