<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core\Services;

use kintai\Core\Container;
use kintai\Core\Repositories\LogRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Services\AuditLogger;
use kintai\Core\Services\Log;
use PHPUnit\Framework\TestCase;

/**
 * logUpdate() exigeait deux tableaux : un état « avant » nul (première sauvegarde des widgets d'un employé, shift déjà
 * supprimé lors d'une résolution de conflit) levait une TypeError, donc une erreur 500 alors que l'action avait réussi.
 */
final class AuditLoggerTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $contexts = [];

    protected function setUp(): void
    {
        $repo = $this->createStub(LogRepositoryInterface::class);
        $repo->method('record')->willReturnCallback(function (...$args): void {
            $this->contexts[] = $args[6];
        });
        $container = new Container();
        $container->instance(LogRepositoryInterface::class, $repo);
        Log::setContainer($container);
    }

    protected function tearDown(): void
    {
        Log::reset();
    }

    public function testNullOldStateIsLoggedAsCreation(): void
    {
        (new AuditLogger())->logUpdate(new Request(), 'dashboard_prefs.updated', 'user_dashboard_prefs', 3, null, ['kpi'], []);

        $this->assertCount(1, $this->contexts);
        $this->assertArrayHasKey('changes', $this->contexts[0]);
    }

    public function testNullNewStateIsAccepted(): void
    {
        (new AuditLogger())->logUpdate(new Request(), 'shift.conflict_resolved', 'shift', 6, null, null, ['deleted_id' => 9]);

        $this->assertSame([['deleted_id' => 9]], $this->contexts);
    }
}
