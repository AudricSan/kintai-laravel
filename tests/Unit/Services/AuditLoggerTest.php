<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use kintai\Core\Request;
use kintai\Core\Services\AuditLogger;

final class AuditLoggerTest extends TestCase
{
    private AuditLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new AuditLogger();
    }

    private function makeRequest(int $userId = 0): Request
    {
        $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'SCRIPT_NAME' => '/index.php', 'REMOTE_ADDR' => '127.0.0.1'];
        $_GET = $_POST = $_COOKIE = $_FILES = [];
        $req = new Request();
        if ($userId > 0) {
            $req->setAttribute('auth_user', ['id' => $userId]);
        }
        return $req;
    }

    public function testLogAcceptsFullSignature(): void
    {
        $this->logger->log($this->makeRequest(1), 'shift.created', 'shift', 42, ['key' => 'value'], 1, 99);
        $this->assertTrue(true);
    }

    public function testLogDoesNotThrowOnAnyException(): void
    {
        $this->logger->log($this->makeRequest(), 'action', 'resource');
        $this->assertTrue(true);
    }
}
