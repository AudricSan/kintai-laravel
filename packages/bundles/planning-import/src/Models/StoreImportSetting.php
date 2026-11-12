<?php

namespace Kintai\Bundles\PlanningImport\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Grille du fichier Excel d'un magasin et pause automatique (voir `GridSettings`).
 *
 * @property int $store_id
 * @property int $col_start
 * @property int $col_end
 * @property int $base_hour
 * @property int $minutes_per_col
 * @property int $block_size
 * @property int $shift_rows
 * @property string $sheet_filter_pattern
 * @property int $auto_pause_after_minutes
 * @property int $auto_pause_minutes
 */
#[Fillable([
    'store_id', 'col_start', 'col_end', 'base_hour', 'minutes_per_col', 'block_size', 'shift_rows', 'sheet_filter_pattern',
    'auto_pause_after_minutes', 'auto_pause_minutes',
])]
class StoreImportSetting extends Model
{
    protected $table = 'store_import_settings';

    protected $primaryKey = 'store_id';

    public $incrementing = false;
}
