<?php

namespace App\Cron;

use App\Models\ScheduledTaskRun;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Throwable;

/**
 * Tâches du planificateur de Laravel (`routes/console.php` et celles des bundles) avec leur fréquence, leur prochaine
 * échéance et le résultat de leur dernière exécution (voir `RecordScheduledTaskRun`).
 */
class ScheduledTasks
{
    /** Longueur maximale du nom d'une tâche (colonne `scheduled_task_runs.name`). */
    private const NAME_MAX = 150;

    /**
     * Nom stable d'une tâche : son nom explicite (`->name()`), sinon sa commande.
     */
    public static function nameOf(Event $event): string
    {
        $name = trim((string) $event->description);

        return mb_substr($name !== '' ? $name : $event->getSummaryForDisplay(), 0, self::NAME_MAX);
    }

    /**
     * @return list<array{name: string, expression: string, next_due: CarbonImmutable|null, last_success_at: CarbonImmutable|null, last_failure_at: CarbonImmutable|null, last_error: string|null}>
     */
    public function all(): array
    {
        // Les tâches se déclarent dans routes/console.php, que seul le noyau console charge.
        app(Kernel::class)->bootstrap();

        $runs = ScheduledTaskRun::query()->get()->keyBy('name');
        $tasks = [];

        foreach (app(Schedule::class)->events() as $event) {
            $name = self::nameOf($event);
            $run = $runs->get($name);

            try {
                $next = CarbonImmutable::instance($event->nextRunDate());
            } catch (Throwable) {
                $next = null;
            }

            $tasks[] = [
                'name' => $name,
                'expression' => $event->expression,
                'next_due' => $next,
                'last_success_at' => $run?->last_success_at !== null ? CarbonImmutable::instance($run->last_success_at) : null,
                'last_failure_at' => $run?->last_failure_at !== null ? CarbonImmutable::instance($run->last_failure_at) : null,
                'last_error' => $run?->last_error,
            ];
        }

        return $tasks;
    }
}
