<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Dernière exécution d'une tâche du planificateur, alimentée par ses événements (`App\Cron\RecordScheduledTaskRun`).
 *
 * @property string $name
 * @property Carbon|null $last_success_at
 * @property Carbon|null $last_failure_at
 * @property string|null $last_error
 * @property int|null $last_runtime_ms
 */
#[Fillable(['name', 'last_success_at', 'last_failure_at', 'last_error', 'last_runtime_ms'])]
class ScheduledTaskRun extends Model
{
    public $timestamps = false;

    protected $primaryKey = 'name';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['last_success_at' => 'datetime', 'last_failure_at' => 'datetime'];
    }
}
