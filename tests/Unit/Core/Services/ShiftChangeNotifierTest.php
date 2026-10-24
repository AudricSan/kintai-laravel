<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core\Services;

use kintai\Contract\Event\ShiftDeleted;
use kintai\Contract\Event\ShiftSaved;
use kintai\Core\BundleContract\Bundle;
use kintai\Core\BundleManager;
use kintai\Core\Extension\Events;
use kintai\Core\Extension\ExtensionRegistry;
use kintai\Core\Services\ShiftChangeNotifier;
use PHPUnit\Framework\TestCase;

/**
 * Annonce des écritures de shifts : événements ShiftSaved / ShiftDeleted (contrat 1.28) pour les bundles, puis synchro
 * d'agenda. Aucune panne de l'un ne doit empêcher l'autre ni remonter à l'écriture.
 */
final class ShiftChangeNotifierTest extends TestCase
{
    /** @param array<string, callable> $listeners classe d'événement => auditeur du bundle « acme » */
    private function events(array $listeners): Events
    {
        $bundle = new class extends Bundle {
            public array $listeners = [];

            public function __construct() {}

            public function getName(): string
            {
                return 'acme';
            }

            public function register(): void {}

            public function eventListeners(): array
            {
                return $this->listeners;
            }
        };
        $bundle->listeners = $listeners;
        $manager = (new \ReflectionClass(BundleManager::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($manager, 'bundles'))->setValue($manager, ['Acme' => $bundle]);

        return new Events(new ExtensionRegistry($manager));
    }

    public function testBundlesReceiveShiftSavedWithThePreviousRow(): void
    {
        $received = [];
        $notifier = new ShiftChangeNotifier($this->events([
            ShiftSaved::class => function (ShiftSaved $e) use (&$received): void {
                $received[] = [$e->isNew(), $e->userId(), $e->previousUserId(), $e->shift['id']];
            },
        ]), fn() => null);

        $notifier->saved(['id' => 5, 'user_id' => 11], ['id' => 5, 'user_id' => 10]);
        $notifier->saved(['id' => 6, 'user_id' => 11], null);
        $notifier->saved(['id' => 7, 'user_id' => 11], ['id' => 7, 'user_id' => 11]);

        $this->assertSame([[false, 11, 10, 5], [true, 11, null, 6], [false, 11, null, 7]], $received);
    }

    public function testBundlesReceiveShiftDeleted(): void
    {
        $received = [];
        $notifier = new ShiftChangeNotifier($this->events([
            ShiftDeleted::class => function (ShiftDeleted $e) use (&$received): void {
                $received[] = [$e->shiftId(), $e->userId()];
            },
        ]), fn() => null);

        $notifier->deleted(['id' => 5, 'user_id' => 10]);

        $this->assertSame([[5, 10]], $received);
    }

    public function testTheCalendarSyncIsToldWhatChangedAndHowEvenWhenABundleListenerFails(): void
    {
        $calls = [];
        $notifier = new ShiftChangeNotifier($this->events([
            ShiftSaved::class => function (): void {
                throw new \RuntimeException('auditeur cassé');
            },
        ]), function (string $kind, array $shift, ?array $before) use (&$calls): void {
            $calls[] = [$kind, $shift['id'], $before['user_id'] ?? null];
        });

        $notifier->saved(['id' => 5, 'user_id' => 11], ['id' => 5, 'user_id' => 10]);
        $notifier->deleted(['id' => 5, 'user_id' => 11]);

        $this->assertSame([['saved', 5, 10], ['deleted', 5, null]], $calls);
    }

    public function testAFailingCalendarSyncNeverThrowsToTheWriter(): void
    {
        $notifier = new ShiftChangeNotifier(new Events(), function (): void {
            throw new \RuntimeException('table calendar_sync_queue absente');
        });

        $notifier->saved(['id' => 5, 'user_id' => 11], null);
        $notifier->deleted(['id' => 5, 'user_id' => 11]);

        $this->addToAssertionCount(1);
    }
}
