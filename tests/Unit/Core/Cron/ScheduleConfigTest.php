<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Cron;

use Cron\CronExpression;
use App\Cron\CronRunner;
use PHPUnit\Framework\TestCase;

/** Planificateur (config/schedule.php, routes/console.php) : chaque tâche du Core y est, avec une expression valide. */
final class ScheduleConfigTest extends TestCase
{
    /** @return array<string, mixed> */
    private function config(): array
    {
        return require dirname(__DIR__, 4) . '/config/schedule.php';
    }

    public function testEveryCoreJobIsScheduled(): void
    {
        $jobs = $this->config()['jobs'];

        foreach (CronRunner::CORE_JOBS as $job) {
            $this->assertArrayHasKey($job, $jobs, "tâche du Core absente du planificateur : {$job}");
        }
    }

    public function testEveryExpressionIsAValidCronExpression(): void
    {
        foreach ($this->config()['jobs'] as $job => $expression) {
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9-]{1,40}$/', $job);
            if ($expression !== null) {
                $this->assertTrue(CronExpression::isValidExpression($expression), "expression invalide pour {$job} : {$expression}");
            }
        }
    }

    public function testTheCronCommandSkipsAMissingJobWhenAskedTo(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 4) . '/routes/console.php');

        // Une tâche de bundle désactivé ne fait pas échouer schedule:run chaque minute.
        $this->assertStringContainsString("'--if-available'", $routes);
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 4) . '/artisan') . ' kintai:cron tache-absente --if-available 2>&1', $output, $code);
        $this->assertSame(0, $code, implode("
", $output));
    }
}
