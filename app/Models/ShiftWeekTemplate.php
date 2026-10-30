<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Semaine type d'un magasin : entrées (jour, employé, horaires) réappliquables sur n'importe quelle semaine. */
final class ShiftWeekTemplate extends Model
{
    protected $table = 'shift_week_templates';
    protected $guarded = [];

    protected function casts(): array
    {
        return ['store_id' => 'integer', 'created_by' => 'integer', 'entries' => 'array'];
    }
}