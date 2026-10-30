<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Validation d'une semaine de feuille de temps par un responsable (une seule par magasin et par semaine). */
final class TimesheetApproval extends Model
{
    protected $table = 'timesheet_approvals';
    protected $guarded = [];

    protected function casts(): array
    {
        return ['store_id' => 'integer', 'approved_by' => 'integer', 'totals' => 'array'];
    }
}