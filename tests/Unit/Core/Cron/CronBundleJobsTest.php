<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core\Cron;

use kintai\Core\BundleContract\Bundle;
use kintai\Core\BundleManager;
use kintai\Core\Cron\CronJobInterface;
use kintai\Core\Cron\CronRunner;
use kintai\Core\Extension\ExtensionRegistry;
use kintai\Core\Repositories\CronTokenRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Response;
use PHPUnit\Framework\TestCase;

/** Tâche de test dont le nom est fixé à la construction. */
final class NamedTestJob implements CronJobInterface
{
    public function __construct(public readonly string $name = 'acme-sync') {}

    public function getName(): string
    {
        return $this->name;
    }

    public function run(Request $request): Response
    {
        return Response::json(['ok' => true, 'job' => $this->name]);
    }
}

/**
 * Tâches planifiées des bundles (étape 2j du contrat de bundle) : Bundle::cronJobs() (contrat 1.8), noms réservés,
 * isolation (plus de tâche historique du Core reprise par un bundle).
 */
final class CronBundleJobsTest extends TestCase
{
    private function runner(): CronRunner
    {
        $runner = new CronRunner($this->createStub(CronTokenRepositoryInterface::class));
        foreach (['backup', 'log-purge', 'license-check'] as $core) {
            $runner->register(new NamedTestJob($core));
        }
        return $runner;
    }

    /** @param array<string, string> $names classe déclarée => nom renvoyé par la tâche construite */
    private static function make(array $names): \Closure
    {
        return static function (string $class) use ($names): object {
            if (!isset($names[$class])) {
                throw new \RuntimeException('construction impossible');
            }
            return new NamedTestJob($names[$class]);
        };
    }

    public function testBundleJobsAreRegisteredWithReservedNamesProtected(): void
    {
        $runner = $this->runner();

        $runner->registerBundleJobs(
            [
                'acme'         => ['A\\Sync', 'A\\Backup', 'A\\Broken', 'A\\BadName', 'A\\AutoValidate'],
                'other'        => ['O\\Sync'],
                'daily-report' => ['D\\AutoValidate'],
            ],
            self::make(['A\\Sync' => 'acme-sync', 'A\\Backup' => 'backup', 'A\\BadName' => 'Bad Name', 'A\\AutoValidate' => 'auto-validate', 'O\\Sync' => 'acme-sync', 'D\\AutoValidate' => 'auto-validate']),
        );

        $this->assertSame(['backup', 'log-purge', 'license-check', 'acme-sync', 'auto-validate'], array_keys($runner->getJobs()));
        $this->assertSame('auto-validate', $runner->getJob('auto-validate')->getName(), 'première déclaration retenue (acme)');
    }

    public function testRegistryKeepsOnlyCronJobClasses(): void
    {
        $bundle = new class extends Bundle {
            public function __construct() {}

            public function getName(): string
            {
                return 'acme';
            }

            public function register(): void {}

            public function cronJobs(): array
            {
                return [NamedTestJob::class, \stdClass::class, 'Nope\\Missing', 42];
            }
        };
        $manager = (new \ReflectionClass(BundleManager::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($manager, 'bundles'))->setValue($manager, ['acme' => $bundle]);

        $this->assertSame(['acme' => [NamedTestJob::class]], (new ExtensionRegistry($manager))->cronJobsByBundle());
    }
}
