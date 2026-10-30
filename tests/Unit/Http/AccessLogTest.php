<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Tests\Support\RecordingLog;
use App\Models\AppSetting;
use Illuminate\Support\Facades\Log;
use App\Http\Middleware\AccessLog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Journal d'accès automatique (AccessLog::terminate(), appelé après l'envoi de chaque réponse) : niveau selon le statut,
 * chemins exclus, réglage de l'instance, et jamais d'exception.
 */
final class AccessLogTest extends TestCase
{
    private RecordingLog $log;

    protected function setUp(): void
    {
        kintai_test_settings();
        $this->log = kintai_test_log();
    }

    protected function tearDown(): void
    {
        app()->forgetInstance(\App\Services\AppSettingsService::class);
    }

    private function terminate(string $uri, int $status): void
    {
        app()->forgetInstance(\App\Services\AppSettingsService::class);

        $request = Request::create($uri, 'GET');
        (new AccessLog())->handle($request, static fn() => new Response());
        (new AccessLog())->terminate($request, new Response('', $status));
    }

    /** @return array<string, array{int, string}> */
    public static function statuses(): array
    {
        return ['succès' => [200, 'info'], 'erreur serveur' => [500, 'error'], 'erreur client' => [404, 'warning']];
    }

    #[DataProvider('statuses')]
    public function testLogsTheRequestOnTheAccessChannelWithALevelFromTheStatus(int $status, string $level): void
    {
        $this->terminate('/admin/shifts', $status);

        $entries = $this->log->on('access');
        $this->assertCount(1, $entries);
        $this->assertSame($level, $entries[0]['level']);
        $this->assertSame('GET /admin/shifts', $entries[0]['message']);
        $this->assertSame($status, $entries[0]['context']['response_status']);
    }

    /** @return array<string, array{string}> */
    public static function excludedPaths(): array
    {
        return [
            'polling des notifications' => ['/notifications/poll'],
            'health-check de l\'API'    => ['/api/v1/ping'],
            'flux en continu'           => ['/messages/42/stream'],
        ];
    }

    #[DataProvider('excludedPaths')]
    public function testSkipsHighFrequencyPaths(string $uri): void
    {
        $this->terminate($uri, 200);

        $this->assertSame([], $this->log->entries);
    }

    public function testSkipsWhenAccessLogDisabledViaSettings(): void
    {
        kintai_test_settings(['access_log_enabled' => '0']);

        $this->terminate('/admin/shifts', 200);

        $this->assertSame([], $this->log->entries);
    }
}