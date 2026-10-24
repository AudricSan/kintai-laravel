<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core\Extension;

use kintai\Contract\Event\EmployeeCreated;
use kintai\Core\BundleContract\Bundle;
use kintai\Core\BundleManager;
use kintai\Core\Extension\Events;
use kintai\Core\Extension\ExtensionRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Événements du Core annoncés aux bundles (contrat 1.13) : auditeurs des bundles actifs (Bundle::eventListeners()),
 * déclarations invalides écartées, auditeur en échec isolé.
 */
final class EventsTest extends TestCase
{
    /** @param array<string, mixed> $active slug => auditeurs déclarés (tableau, ou \Throwable levée) */
    private function events(array $active): Events
    {
        $bundles = [];
        foreach ($active as $slug => $declared) {
            $bundle = new class extends Bundle {
                public string $slug = '';
                public mixed $declared = [];

                public function __construct() {}

                public function getName(): string
                {
                    return $this->slug;
                }

                public function register(): void {}

                public function eventListeners(): array
                {
                    if ($this->declared instanceof \Throwable) {
                        throw $this->declared;
                    }
                    return $this->declared;
                }
            };
            $bundle->slug = $slug;
            $bundle->declared = $declared;
            $bundles['B' . $slug] = $bundle;
        }
        $manager = (new \ReflectionClass(BundleManager::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($manager, 'bundles'))->setValue($manager, $bundles);

        return new Events(new ExtensionRegistry($manager));
    }

    private static function event(): EmployeeCreated
    {
        return new EmployeeCreated(['id' => 7, 'display_name' => 'Sato', 'password_hash' => 'secret'], 3, 1, 'form');
    }

    public function testEachListeningBundleReceivesTheEvent(): void
    {
        $received = [];
        $events = $this->events([
            'hiring-report' => [EmployeeCreated::class => function (EmployeeCreated $e) use (&$received): void {
                $received[] = ['hiring-report', $e->userId(), $e->storeId, $e->createdBy, $e->source];
            }],
            'acme' => [EmployeeCreated::class => function (EmployeeCreated $e) use (&$received): void {
                $received[] = ['acme', $e->userId(), $e->storeId, $e->createdBy, $e->source];
            }],
            'silent' => [],
        ]);

        $events->dispatch(self::event());

        $this->assertSame([['hiring-report', 7, 3, 1, 'form'], ['acme', 7, 3, 1, 'form']], $received);
        $this->assertTrue($events->isListenedBy('hiring-report', EmployeeCreated::class));
        $this->assertFalse($events->isListenedBy('silent', EmployeeCreated::class));
        $this->assertFalse($events->isListenedBy('absent', EmployeeCreated::class));
    }

    /** Les bundles ne reçoivent jamais le hash du mot de passe. */
    public function testSecretsAreNotPassedToBundles(): void
    {
        $event = self::event();

        $this->assertArrayNotHasKey('password_hash', $event->user);
        $this->assertSame('Sato', $event->user['display_name']);
    }

    /** Un auditeur en échec n'empêche ni les suivants ni l'action du Core. */
    public function testFailingListenerIsIsolated(): void
    {
        $received = [];
        $events = $this->events([
            'boom' => [EmployeeCreated::class => fn() => throw new \RuntimeException('kaput')],
            'acme' => [EmployeeCreated::class => function () use (&$received): void {
                $received[] = 'acme';
            }],
        ]);

        $events->dispatch(self::event());

        $this->assertSame(['acme'], $received);
    }

    /** Événement hors contrat, auditeur non appelable ou déclaration en échec : écartés. */
    public function testInvalidDeclarationsAreIgnored(): void
    {
        $events = $this->events([
            'not-contract' => [\stdClass::class => fn() => null, 'kintai\\Contract\\Event\\Nope' => fn() => null],
            'not-callable' => [EmployeeCreated::class => 'pas une fonction'],
            'throws' => new \RuntimeException('kaput'),
        ]);

        $this->assertFalse($events->isListenedBy('not-contract', \stdClass::class));
        $this->assertFalse($events->isListenedBy('not-callable', EmployeeCreated::class));
        $this->assertFalse($events->isListenedBy('throws', EmployeeCreated::class));
        $events->dispatch(self::event());
        $this->addToAssertionCount(1);
    }

    public function testNoRegistryMeansNoListener(): void
    {
        $events = new Events();

        $events->dispatch(self::event());
        $this->assertFalse($events->isListenedBy('hiring-report', EmployeeCreated::class));
    }
}
