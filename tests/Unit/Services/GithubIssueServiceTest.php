<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Services;

use kintai\Core\Services\GithubIssueService;
use PHPUnit\Framework\TestCase;

final class GithubIssueServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('GITHUB_ISSUES_TOKEN');
        putenv('GITHUB_UPDATE_REPO');
    }

    public function testIsConfiguredFalseWithoutToken(): void
    {
        putenv('GITHUB_ISSUES_TOKEN');

        $service = new GithubIssueService();

        $this->assertFalse($service->isConfigured());
    }

    public function testIsConfiguredTrueWithToken(): void
    {
        putenv('GITHUB_ISSUES_TOKEN=ghp_test_token');

        $service = new GithubIssueService();

        $this->assertTrue($service->isConfigured());
    }

    public function testCreateIssueFailsWhenNotConfigured(): void
    {
        putenv('GITHUB_ISSUES_TOKEN');

        $service = new GithubIssueService();
        $result = $service->createIssue('Bug title', 'Bug body');

        $this->assertFalse($result['ok']);
        $this->assertSame('not_configured', $result['error']);
    }

    public function testCreateIssueReturnsIssueUrlOnSuccess(): void
    {
        putenv('GITHUB_ISSUES_TOKEN=ghp_test_token');
        putenv('GITHUB_UPDATE_REPO=AudricSan/Kintai');

        $poster = fn(string $url, array $headers, string $payload): ?string =>
            json_encode(['html_url' => 'https://github.com/AudricSan/Kintai/issues/42']);

        $service = new GithubIssueService($poster);
        $result = $service->createIssue('Bug title', 'Bug body');

        $this->assertTrue($result['ok']);
        $this->assertSame('https://github.com/AudricSan/Kintai/issues/42', $result['issue_url']);
    }

    public function testCreateIssueFailsWhenRequestFails(): void
    {
        putenv('GITHUB_ISSUES_TOKEN=ghp_test_token');

        $poster = fn(string $url, array $headers, string $payload): ?string => null;

        $service = new GithubIssueService($poster);
        $result = $service->createIssue('Bug title', 'Bug body');

        $this->assertFalse($result['ok']);
        $this->assertSame('request_failed', $result['error']);
    }

    public function testCreateIssueFailsOnInvalidResponse(): void
    {
        putenv('GITHUB_ISSUES_TOKEN=ghp_test_token');

        $poster = fn(string $url, array $headers, string $payload): ?string =>
            json_encode(['message' => 'Bad credentials']);

        $service = new GithubIssueService($poster);
        $result = $service->createIssue('Bug title', 'Bug body');

        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_response', $result['error']);
    }

    public function testFallbackUrlPrefillsTitleAndDescription(): void
    {
        putenv('GITHUB_UPDATE_REPO=AudricSan/Kintai');

        $service = new GithubIssueService();
        $url = $service->fallbackUrl('My bug', 'It broke');

        $this->assertStringStartsWith('https://github.com/AudricSan/Kintai/issues/new?', $url);
        $this->assertStringContainsString('title=My+bug', $url);
        $this->assertStringContainsString('body=It+broke', $url);
    }
}
