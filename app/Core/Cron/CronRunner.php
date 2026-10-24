<?php

declare(strict_types=1);

namespace kintai\Core\Cron;

use kintai\Core\Exceptions\ForbiddenException;
use kintai\Core\Exceptions\NotFoundException;
use kintai\Core\Repositories\CronTokenRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Response;
use kintai\Core\Services\Log;

final class CronRunner
{
    /** Tâches du Core : un bundle ne peut pas les remplacer. */
    public const CORE_JOBS = ['backup', 'log-purge', 'license-check', 'notification-purge', 'system-notifications', 'calendar-sync'];


    /** @var array<string, CronJobInterface> */
    private array $jobs = [];

    public function __construct(
        private readonly CronTokenRepositoryInterface $cronTokens,
    ) {}

    public function register(CronJobInterface $job): void
    {
        $this->jobs[$job->getName()] = $job;
    }

    /**
     * Enregistre les tâches déclarées par les bundles actifs (Bundle::cronJobs(), contrat 1.8). Chaque tâche est
     * isolée : une classe impossible à construire, un nom invalide, réservé au Core ou déjà pris est ignoré et
     * journalisé.
     *
     * @param array<string, list<class-string>> $bySlug
     * @param callable(class-string): object    $make
     */
    public function registerBundleJobs(array $bySlug, callable $make): void
    {
        foreach ($bySlug as $slug => $classes) {
            foreach ($classes as $class) {
                try {
                    $job = $make($class);
                    $name = $job instanceof CronJobInterface ? $job->getName() : '';
                } catch (\Throwable $e) {
                    $this->warn('bundle_extension_failed', $slug, $e->getMessage());
                    continue;
                }
                if (preg_match('/^[a-z][a-z0-9-]{1,40}$/', $name) !== 1 || in_array($name, self::CORE_JOBS, true)
                    || isset($this->jobs[$name])) {
                    $this->warn('bundle_extension_invalid', $slug, $name);
                    continue;
                }
                $this->jobs[$name] = $job;
            }
        }
    }

    private function warn(string $event, string $slug, string $detail): void
    {
        try {
            Log::warning($event, ['bundle' => $slug, 'point' => 'cronJobs', 'detail' => $detail]);
        } catch (\Throwable) {
        }
    }

    public function run(string $jobName, Request $request): Response
    {
        $job = $this->jobs[$jobName] ?? null;
        if ($job === null) {
            throw new NotFoundException(__('error_cron_unknown_job', ['job' => $jobName]));
        }

        $rawToken = $this->extractToken($request);
        if ($rawToken === null) {
            throw new ForbiddenException(__('error_cron_token_missing'));
        }

        $hashed = hash_token($rawToken);
        $record = $this->cronTokens->findByToken($hashed);
        if ($record === null) {
            throw new ForbiddenException(__('error_cron_token_invalid'));
        }

        if (!empty($record['expires_at']) && $record['expires_at'] < date('Y-m-d H:i:s')) {
            throw new ForbiddenException(__('error_cron_token_expired'));
        }

        if (isset($record['job_name']) && $record['job_name'] !== '' && $record['job_name'] !== $jobName) {
            throw new ForbiddenException(__('error_cron_token_not_allowed'));
        }

        $this->cronTokens->touchLastUsed((int) $record['id']);

        return $job->run($request);
    }

    /**
     * Lance une tâche sans jeton : réservé à la ligne de commande (scripts/cron.php, crontab d'un serveur avec accès
     * SSH), où l'accès au serveur vaut autorisation. Les appels HTTP passent toujours par run(), avec jeton.
     */
    public function runLocal(string $jobName, Request $request): Response
    {
        $job = $this->jobs[$jobName] ?? null;
        if ($job === null) {
            throw new NotFoundException(__('error_cron_unknown_job', ['job' => $jobName]));
        }
        return $job->run($request);
    }

    /** @return CronJobInterface[] */
    public function getJobs(): array
    {
        return $this->jobs;
    }

    public function getJob(string $name): ?CronJobInterface
    {
        return $this->jobs[$name] ?? null;
    }

    /** @return array{name: string, label: string}[] */
    public function getJobMeta(): array
    {
        $meta = [];
        foreach ($this->jobs as $name => $job) {
            $meta[] = ['name' => $name, 'label' => self::label($name)];
        }
        return $meta;
    }

    /**
     * Libellé d'une tâche, par convention de traduction (contrat 1.20) : clé cron_job_{nom}, tirets remplacés par
     * des soulignés (ex. cron_job_auto_validate), fournie par le Core ou par le bundle dans ses fichiers lang/ ;
     * à défaut, le nom de la tâche.
     */
    public static function label(string $name): string
    {
        return translation_or($name, 'cron_job_' . str_replace('-', '_', $name));
    }

    private function extractToken(Request $request): ?string
    {
        $fromQuery = $request->query('token', '');
        if ($fromQuery !== '') {
            return $fromQuery;
        }

        $header = $request->header('Authorization');
        if ($header !== null && preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            return $m[1];
        }

        return null;
    }
}
