<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Controller\Web;

use kintai\Core\BundleContract\Bundle;
use kintai\Core\BundleManager;
use kintai\Core\Exceptions\ForbiddenException;
use kintai\Core\Exceptions\NotFoundException;
use kintai\Core\Request;
use kintai\UI\Controller\Web\BundleAssetController;
use PHPUnit\Framework\TestCase;

final class FakeControllerAssetBundle extends Bundle
{
    public function __construct(private readonly string $assetsPath)
    {
    }

    public function getName(): string
    {
        return 'fake-bundle';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function register(): void
    {
    }

    public function getAssetsPath(): ?string
    {
        return $this->assetsPath;
    }
}

final class BundleAssetControllerTest extends TestCase
{
    private string $assetsDir;
    private BundleAssetController $controller;

    protected function setUp(): void
    {
        $this->assetsDir = sys_get_temp_dir() . '/kintai-bundle-assets-test-' . uniqid();
        mkdir($this->assetsDir . '/css', 0775, true);
        file_put_contents($this->assetsDir . '/css/style.css', '.foo{color:red}');
        file_put_contents($this->assetsDir . '/css/script.php', '<?php echo 1;');
        file_put_contents(dirname($this->assetsDir) . '/kintai-secret-' . basename($this->assetsDir) . '.txt', 'secret');

        $manager = (new \ReflectionClass(BundleManager::class))->newInstanceWithoutConstructor();
        $bundle = new FakeControllerAssetBundle($this->assetsDir);

        $bundlesProperty = new \ReflectionProperty(BundleManager::class, 'bundles');
        $bundlesProperty->setAccessible(true);
        $bundlesProperty->setValue($manager, [FakeControllerAssetBundle::class => $bundle]);

        $this->controller = new BundleAssetController($manager);
    }

    protected function tearDown(): void
    {
        @unlink(dirname($this->assetsDir) . '/kintai-secret-' . basename($this->assetsDir) . '.txt');
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->assetsDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->assetsDir);
    }

    private function makeRequest(string $slug, string $path): Request
    {
        $request = new Request();
        $request->setRouteParams(['slug' => $slug, 'path' => $path]);
        return $request;
    }

    public function testServesExistingCssFile(): void
    {
        $response = $this->controller->serve($this->makeRequest('fake-bundle', 'css/style.css'));

        $this->assertSame(200, $response->status());
        $reflection = new \ReflectionProperty($response, 'headers');
        $headers = $reflection->getValue($response);
        $this->assertSame('text/css', $headers['Content-Type']);
        $this->assertStringContainsString('immutable', $headers['Cache-Control']);
        $this->assertSame('nosniff', $headers['X-Content-Type-Options']);
        $this->assertStringContainsString('inline', $headers['Content-Disposition']);
    }

    public function testUnknownBundleThrowsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->controller->serve($this->makeRequest('some-other-slug', 'css/style.css'));
    }

    public function testMissingFileThrowsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->controller->serve($this->makeRequest('fake-bundle', 'css/absent.css'));
    }

    public function testRejectsPathTraversal(): void
    {
        $this->expectException(ForbiddenException::class);
        $this->controller->serve($this->makeRequest(
            'fake-bundle',
            '../kintai-secret-' . basename($this->assetsDir) . '.txt'
        ));
    }

    public function testRejectsDisallowedExtension(): void
    {
        $this->expectException(ForbiddenException::class);
        $this->controller->serve($this->makeRequest('fake-bundle', 'css/script.php'));
    }
}
