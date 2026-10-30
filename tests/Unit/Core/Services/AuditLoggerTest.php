<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Services;

use App\Services\AuditLogger;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use Tests\Support\RecordingLog;

/**
 * logUpdate() exigeait deux tableaux : un état « avant » nul (première sauvegarde des widgets d'un employé, shift déjà
 * supprimé lors d'une résolution de conflit) levait une TypeError, donc une erreur 500 alors que l'action avait réussi.
 */
final class AuditLoggerTest extends TestCase
{
    private RecordingLog $log;

    protected function setUp(): void
    {
        $this->log = kintai_test_log();
    }

    public function testLogWritesOneAuditEntry(): void
    {
        (new AuditLogger())->log(Request::capture(), 'shift.created', 'shift', 42, ['key' => 'val'], 2, 1);

        $entries = $this->log->on('audit');
        $this->assertCount(1, $entries);
        $this->assertSame('shift.created', $entries[0]['message']);
        $this->assertSame(
            ['action' => 'shift.created', 'resource_type' => 'shift', 'resource_id' => 42, 'user_id' => 1, 'store_id' => 2, 'key' => 'val'],
            $entries[0]['context'],
        );
    }

    public function testNullOldStateIsLoggedAsCreation(): void
    {
        (new AuditLogger())->logUpdate(Request::capture(), 'dashboard_prefs.updated', 'user_dashboard_prefs', 3, null, ['kpi'], []);

        $entries = $this->log->on('audit');
        $this->assertCount(1, $entries);
        $this->assertArrayHasKey('changes', $entries[0]['context']);
    }

    public function testNullNewStateIsAccepted(): void
    {
        (new AuditLogger())->logUpdate(Request::capture(), 'shift.conflict_resolved', 'shift', 6, null, null, ['deleted_id' => 9]);

        $this->assertSame(9, $this->log->on('audit')[0]['context']['deleted_id']);
    }
}