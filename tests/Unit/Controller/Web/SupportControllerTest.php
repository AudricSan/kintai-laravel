<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Controller\Web;

use kintai\Core\Container;
use kintai\Core\Repositories\LogRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Services\AuditLogger;
use kintai\Core\Services\GithubIssueService;
use kintai\Core\Services\Log;
use kintai\Core\Services\UpdateService;
use kintai\UI\Controller\Web\SupportController;
use PHPUnit\Framework\TestCase;

final class SupportControllerTest extends TestCase
{
    private LogRepositoryInterface $logRepo;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->logRepo = $this->createStub(LogRepositoryInterface::class);
        $container = new Container();
        $container->instance(LogRepositoryInterface::class, $this->logRepo);
        Log::setContainer($container);

        $this->tmpDir = sys_get_temp_dir() . '/kintai_supportctrl_test_' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir . '/config', 0775, true);
        file_put_contents($this->tmpDir . '/config/app.php', "<?php return ['version' => '9.9.9'];");
    }

    protected function tearDown(): void
    {
        $_GET = [];
        $_POST = [];
        $_SERVER = [];
        Log::reset();
        putenv('GITHUB_ISSUES_TOKEN');
        putenv('GITHUB_UPDATE_REPO');

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmpDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->tmpDir);
    }

    private function locationOf(\kintai\Core\Response $response): string
    {
        $ref = new \ReflectionProperty($response, 'headers');
        $ref->setAccessible(true);
        return $ref->getValue($response)['Location'] ?? '';
    }

    private function makeController(?\Closure $poster = null): SupportController
    {
        return new SupportController(
            new GithubIssueService($poster),
            new AuditLogger(),
            new UpdateService($this->tmpDir),
        );
    }

    public function testReportIssueRejectsEmptyFields(): void
    {
        $_POST = ['title' => '', 'description' => '', 'return_to' => '/employee'];
        $req = new Request();
        $req->setAttribute('auth_user', ['id' => 1]);

        $poster = function (): never {
            throw new \RuntimeException('should not call GitHub API');
        };

        $response = $this->makeController($poster)->reportIssue($req);

        $this->assertSame(302, $response->status());
        $this->assertStringContainsString('ri_error=empty', $this->locationOf($response));
    }

    public function testReportIssueCreatesIssueAndRedirectsWithUrl(): void
    {
        putenv('GITHUB_ISSUES_TOKEN=ghp_test_token');
        $_POST = [
            'title'       => 'Le bouton export ne répond pas',
            'description' => "Rien ne se passe au clic.",
            'return_to'   => '/employee/shifts',
        ];
        $req = new Request();
        $req->setAttribute('auth_user', ['id' => 7]);

        $poster = fn(): string => json_encode(['html_url' => 'https://github.com/AudricSan/Kintai/issues/99']);

        $logRepo = $this->createMock(LogRepositoryInterface::class);
        $logRepo->expects($this->once())->method('record');
        $container = new Container();
        $container->instance(LogRepositoryInterface::class, $logRepo);
        Log::setContainer($container);

        $response = $this->makeController($poster)->reportIssue($req);

        $this->assertSame(302, $response->status());
        $this->assertStringContainsString(
            'ri_success=' . urlencode('https://github.com/AudricSan/Kintai/issues/99'),
            $this->locationOf($response)
        );
    }

    public function testReportIssueFallsBackToPrefilledGithubUrlWhenNotConfigured(): void
    {
        putenv('GITHUB_ISSUES_TOKEN');
        putenv('GITHUB_UPDATE_REPO=AudricSan/Kintai');
        $_POST = ['title' => 'Bug', 'description' => 'Description', 'return_to' => '/employee'];
        $req = new Request();
        $req->setAttribute('auth_user', ['id' => 1]);

        $response = $this->makeController()->reportIssue($req);

        $this->assertSame(302, $response->status());
        $this->assertStringStartsWith(
            'https://github.com/AudricSan/Kintai/issues/new?',
            $this->locationOf($response)
        );
    }

    public function testReportIssueRedirectsWithErrorWhenRequestFails(): void
    {
        putenv('GITHUB_ISSUES_TOKEN=ghp_test_token');
        $_POST = ['title' => 'Bug', 'description' => 'Description', 'return_to' => '/employee'];
        $req = new Request();
        $req->setAttribute('auth_user', ['id' => 1]);

        $poster = fn(): ?string => null;

        $response = $this->makeController($poster)->reportIssue($req);

        $this->assertSame(302, $response->status());
        $this->assertStringContainsString('ri_error=send_failed', $this->locationOf($response));
    }

    public function testReportIssueRejectsUnsafeReturnToAndFallsBackToRoot(): void
    {
        $_POST = ['title' => '', 'description' => '', 'return_to' => '//evil.test'];
        $req = new Request();
        $req->setAttribute('auth_user', ['id' => 1]);

        $response = $this->makeController()->reportIssue($req);

        $location = $this->locationOf($response);
        $this->assertStringStartsWith('/?ri_error=empty', $location);
    }
}
