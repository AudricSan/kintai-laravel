<?php

declare(strict_types=1);

namespace Tests\Unit\Logging;

use App\Models\ActivityEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Journal d'activité : les canaux Monolog (business, audit, access) écrivent dans activity_log, avec le contexte de la
 * requête. Audit du 01/10/2026 (point 5) : l'utilisateur ne vient jamais de $_REQUEST, l'adresse jamais de
 * X-Forwarded-For / Client-IP, deux en-têtes envoyés par le client.
 */
final class ActivityLogHandlerTest extends TestCase
{
    private array $server;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        kintai_test_database();
        kintai_test_migrate();
    }

    protected function tearDown(): void
    {
        $_SERVER  = $this->server;
        $_REQUEST = [];
        app()->instance('request', Request::create('/'));
    }

    /** @return array<string, array{string}> */
    public static function levels(): array
    {
        return ['debug' => ['debug'], 'info' => ['info'], 'warning' => ['warning'], 'error' => ['error'], 'critical' => ['critical']];
    }

    #[DataProvider('levels')]
    public function testEachLevelIsWrittenOnTheBusinessChannel(string $level): void
    {
        Log::$level('message ' . $level);

        $entry = ActivityEntry::first();
        $this->assertSame($level, $entry->level);
        $this->assertSame('business', $entry->channel);
        $this->assertSame('message ' . $level, $entry->message);
    }

    public function testNamedChannelsBecomeTheChannelColumn(): void
    {
        Log::channel('audit')->info('shift.created');
        Log::channel('access')->info('GET /');

        $this->assertSame(['audit', 'access'], ActivityEntry::orderBy('id')->pluck('channel')->all());
    }

    public function testReservedContextKeysFillTheirOwnColumns(): void
    {
        Log::channel('audit')->info('Shift created', [
            'action' => 'shift.created', 'resource_type' => 'shift', 'resource_id' => 42, 'user_id' => 1, 'store_id' => 2,
            'response_status' => 201, 'duration_ms' => 150, 'key' => 'val',
        ]);

        $entry = ActivityEntry::first();
        $this->assertSame('shift.created', $entry->action);
        $this->assertSame('shift', $entry->resource_type);
        $this->assertSame(42, $entry->resource_id);
        $this->assertSame(1, $entry->user_id);
        $this->assertSame(2, $entry->store_id);
        $this->assertSame(201, $entry->response_status);
        $this->assertSame(150, $entry->duration_ms);
        $this->assertSame('{"key":"val"}', $entry->context);
    }

    public function testAnExceptionInTheContextIsStoredAsItsMessage(): void
    {
        Log::error('boom', ['exception' => new \RuntimeException('db down')]);

        $this->assertSame('{"exception":"db down"}', ActivityEntry::first()->context);
    }

    public function testUserComesFromTheAuthenticatedRequestNotFromTheQueryString(): void
    {
        $_SERVER  = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/x?auth_user[id]=1', 'SCRIPT_NAME' => '/index.php', 'REMOTE_ADDR' => '203.0.113.7'];
        $_REQUEST = ['auth_user' => ['id' => '1', 'store_id' => '1']];
        $request  = Request::create('/x');
        $request->attributes->set('auth_user', ['id' => 42, 'store_id' => 3]);
        app()->instance('request', $request);

        Log::error('boom');

        $entry = ActivityEntry::first();
        $this->assertSame(42, $entry->user_id);
        $this->assertSame(3, $entry->store_id);
    }

    public function testForgedUserIsIgnoredForAnonymousVisitors(): void
    {
        $_SERVER  = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/x', 'SCRIPT_NAME' => '/index.php', 'REMOTE_ADDR' => '203.0.113.7'];
        $_REQUEST = ['auth_user' => ['id' => '1']];
        app()->instance('request', Request::create('/x'));

        Log::error('boom');

        $this->assertNull(ActivityEntry::first()->user_id);
    }

    public function testIpIsTheRealConnectionAddress(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/x', 'SCRIPT_NAME' => '/index.php',
            'REMOTE_ADDR' => '203.0.113.7', 'HTTP_X_FORWARDED_FOR' => '10.6.6.6', 'HTTP_CLIENT_IP' => '10.7.7.7',
        ];

        Log::error('boom');

        $entry = ActivityEntry::first();
        $this->assertSame('203.0.113.7', $entry->ip_address);
        $this->assertSame('GET', $entry->request_method);
        $this->assertSame('/x', $entry->request_uri);
    }

    public function testWritingNeverThrowsWhenTheDatabaseIsDown(): void
    {
        \Illuminate\Support\Facades\Schema::drop('activity_log');

        Log::error('still fine');

        $this->addToAssertionCount(1);
    }
}
