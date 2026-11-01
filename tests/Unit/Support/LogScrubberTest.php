<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\LogScrubber;
use PHPUnit\Framework\TestCase;

final class LogScrubberTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function secrets(): array
    {
        return [
            'bearer'         => ['Authorization failed for Bearer abcdef0123456789', 'Authorization failed for Bearer ***'],
            'password field' => ['Invalid input password=hunter2&name=Ann', 'Invalid input password=***&name=Ann'],
            'json token'     => ['payload {"token":"abc123xyz","id":4}', 'payload {"token":"***","id":4}'],
            'dsn'            => ['connect mysql://root:s3cret@db.example/kintai failed', 'connect mysql://root:***@db.example/kintai failed'],
            'ical link'      => ['GET /ical/0123456789abcdef failed', 'GET /ical/*** failed'],
            'reset link'     => ['mail to /reset-password/ffeeddcc00112233?x=1', 'mail to /reset-password/***?x=1'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('secrets')]
    public function testSecretsAreMasked(string $input, string $expected): void
    {
        $this->assertSame($expected, LogScrubber::scrub($input));
    }

    public function testPlainMessagesAreUntouched(): void
    {
        $this->assertSame('Division by zero in the report', LogScrubber::scrub('Division by zero in the report'));
    }

    public function testTraceDropsTheArguments(): void
    {
        $fail = static function (string $password): void {
            throw new \RuntimeException('boom');
        };
        try {
            $fail('hunter2-secret');
            $this->fail('exception attendue');
        } catch (\RuntimeException $e) {
            $trace = LogScrubber::trace($e);
        }

        $this->assertStringNotContainsString('hunter2', $trace);
        $this->assertStringContainsString('{main}', $trace);
    }
}