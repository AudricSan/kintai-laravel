<?php

namespace App\Cron;

use App\Models\ScheduledTaskRun;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\Event;
use Throwable;

/**
 * Mémorise le résultat de chaque exécution d'une tâche du planificateur (écran « Tâches planifiées »). Ne lève jamais :
 * un souci d'enregistrement ne doit pas interrompre les tâches suivantes.
 */
class RecordScheduledTaskRun
{
    /** Longueur maximale du message d'erreur conservé. */
    private const ERROR_MAX = 500;

    public function finished(ScheduledTaskFinished $event): void
    {
        // Une commande qui sort en erreur déclenche aussi `ScheduledTaskFailed` : l'échec est enregistré là.
        if ((int) $event->task->exitCode > 0) {
            return;
        }

        $this->record($event->task, [
            'last_success_at' => now(),
            'last_runtime_ms' => (int) round($event->runtime * 1000),
        ]);
    }

    public function failed(ScheduledTaskFailed $event): void
    {
        $this->record($event->task, [
            'last_failure_at' => now(),
            'last_error' => mb_substr(trim($event->exception->getMessage()) ?: $event->exception::class, 0, self::ERROR_MAX),
        ]);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function record(Event $task, array $values): void
    {
        try {
            ScheduledTaskRun::query()->updateOrCreate(['name' => ScheduledTasks::nameOf($task)], $values);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
