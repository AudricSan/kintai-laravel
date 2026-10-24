<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Services;

use kintai\Core\Auth\OwnerGuard;
use kintai\Core\Auth\PermissionService;
use kintai\Core\Repositories\RoleAssignmentRepositoryInterface;
use kintai\Core\Repositories\RoleRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use kintai\Core\Services\AppSettingsService;
use kintai\Core\Services\NotificationService;
use kintai\Core\Services\SystemAlertSourceInterface;
use kintai\Core\Services\SystemNotificationService;
use kintai\Tests\Support\InMemoryAppSettings;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Notifications système aux Owners : une seule notification par changement (anti-doublon), retrait de la cloche quand
 * la cause disparaît, Owners seulement, un détecteur en panne n'arrête pas les autres, pas de réseau hors cron.
 */
final class SystemNotificationServiceTest extends TestCase
{
    private NotificationService $notifs;
    private AppSettingsService $settings;
    /** @var array<string, array|string|null> type => alerte courante (null = tout va bien, 'boom' = détecteur en panne) */
    private array $alerts = [];
    /** @var list<string> types dont le détecteur a été appelé */
    private array $called = [];

    protected function setUp(): void
    {
        $this->notifs   = $this->createStub(NotificationService::class);
        $this->settings = new AppSettingsService(new InMemoryAppSettings());
        $this->alerts   = [];
        $this->called   = [];
    }

    /** @param int[] $ownerIds */
    private function service(array $ownerIds = [1, 2]): SystemNotificationService
    {
        $assignments = $this->createStub(RoleAssignmentRepositoryInterface::class);
        $assignments->method('findByScope')->willReturn(array_map(
            fn(int $id): array => ['id' => $id, 'user_id' => $id, 'role_id' => 10, 'scope_type' => 'global', 'scope_id' => null],
            $ownerIds
        ));
        $roles = $this->createStub(RoleRepositoryInterface::class);
        $roles->method('findById')->willReturn(['id' => 10, 'is_system' => 1]);
        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findById')->willReturnCallback(fn(int $id): array => ['id' => $id, 'is_active' => 1, 'deleted_at' => null]);

        $source = new class($this) implements SystemAlertSourceInterface {
            public function __construct(private readonly SystemNotificationServiceTest $test) {}

            public function all(): array
            {
                $out = [];
                foreach (['core_update_available', 'migration_pending', 'bundle_update_available'] as $type) {
                    $out[$type] = fn() => $this->test->detect($type);
                }
                return $out;
            }
        };

        return new SystemNotificationService(new OwnerGuard(new PermissionService($assignments, $roles), $users), $this->notifs, $this->settings, $source);
    }

    /** @internal appelé par la source anonyme du test */
    public function detect(string $type): ?array
    {
        $this->called[] = $type;
        $alert = $this->alerts[$type] ?? null;
        if ($alert === 'boom') {
            throw new \RuntimeException('réseau');
        }
        return $alert;
    }

    public function testNotifiesEveryOwnerOnceWithTheStableReference(): void
    {
        $this->alerts['migration_pending'] = ['key' => 'k1', 'link' => '/admin/update', 'params' => ['count' => 2]];
        $this->notifs = $this->createMock(NotificationService::class);
        $this->notifs->expects($this->once())->method('notifyMany')
            ->with([1, 2], 'migration_pending', 'notif_migration_pending_body', ['count' => 2], SystemNotificationService::REFERENCE_IDS['migration_pending'], '/admin/update');

        $this->assertSame(['migration_pending'], $this->service()->run());
    }

    public function testSameAlertIsNotNotifiedTwiceButANewKeyIs(): void
    {
        $this->alerts['core_update_available'] = ['key' => '1.2.0', 'link' => '/admin/update'];
        $this->notifs = $this->createMock(NotificationService::class);
        $this->notifs->expects($this->exactly(2))->method('notifyMany');

        $service = $this->service();
        $service->run();
        $this->assertSame([], $service->run(), 'même version : pas de doublon');

        $this->alerts['core_update_available']['key'] = '1.3.0';
        $this->assertSame(['core_update_available'], $service->run(), 'nouvelle version : nouvelle notification');
    }

    public function testNotificationIsResolvedWhenTheCauseDisappears(): void
    {
        $this->alerts['migration_pending'] = ['key' => 'k1', 'link' => '/admin/update'];
        $service = $this->service();
        $service->run();

        $this->alerts['migration_pending'] = null;
        $reads = [];
        $this->notifs->method('markReadFor')->willReturnCallback(function (int $uid, string $type, $id) use (&$reads): void {
            $reads[] = [$uid, $type, $id];
        });
        $service->run();

        $this->assertSame([[1, 'system', 2], [2, 'system', 2]], $reads);

        // Et si la cause revient, on prévient de nouveau.
        $this->alerts['migration_pending'] = ['key' => 'k1', 'link' => '/admin/update'];
        $this->assertSame(['migration_pending'], $service->run());
    }

    public function testNothingIsDoneWithoutAnActiveOwner(): void
    {
        $this->alerts['migration_pending'] = ['key' => 'k1', 'link' => '/admin/update'];
        $this->notifs = $this->createMock(NotificationService::class);
        $this->notifs->expects($this->never())->method('notifyMany');

        $this->assertSame([], $this->service([])->run());
        $this->assertSame([], $this->called, 'aucun détecteur inutilement lancé');
    }

    public function testAFailingDetectorDoesNotStopTheOthersNorClearItsState(): void
    {
        $this->alerts['core_update_available'] = ['key' => '1.2.0', 'link' => '/admin/update'];
        $service = $this->service();
        $service->run();

        $this->alerts['core_update_available'] = 'boom';
        $this->alerts['migration_pending']     = ['key' => 'k1', 'link' => '/admin/update'];
        $this->notifs = $this->createMock(NotificationService::class);
        $this->notifs->expects($this->never())->method('markReadFor');

        $this->assertSame(['migration_pending'], $service->run());
    }

    public function testNetworkDetectorsAreSkippedOutsideTheCron(): void
    {
        $this->alerts['core_update_available']   = ['key' => '1.2.0', 'link' => '/admin/update'];
        $this->alerts['bundle_update_available'] = ['key' => 'b', 'link' => '/admin/bundles/market'];
        $this->alerts['migration_pending']       = ['key' => 'k1', 'link' => '/admin/update'];

        $this->assertSame(['migration_pending'], $this->service()->run(false));
        $this->assertSame(['migration_pending'], $this->called);
    }

    public function testFallbackRunsAtMostOncePerInterval(): void
    {
        $this->alerts['migration_pending'] = ['key' => 'k1', 'link' => '/admin/update'];
        $service = $this->service();

        $this->assertSame(['migration_pending'], $service->runIfDue(3600));
        $this->assertSame([], $service->runIfDue(3600));
        $this->assertCount(1, array_keys($this->called, 'migration_pending'));
    }
}
