<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Controller\Web;

use kintai\Core\Exceptions\ForbiddenException;
use kintai\Core\Exceptions\NotFoundException;
use kintai\Core\Request;
use kintai\UI\Controller\Web\StorageFileController;
use PHPUnit\Framework\TestCase;

final class StorageFileControllerTest extends TestCase
{
    private string $uploads;
    private StorageFileController $controller;

    protected function setUp(): void
    {
        $this->uploads = sys_get_temp_dir() . '/kintai-storage-test-' . uniqid();
        mkdir($this->uploads . '/img/3/7', 0775, true);
        file_put_contents($this->uploads . '/img/3/7/photo_1.jpg', 'fake-jpeg');
        file_put_contents($this->uploads . '/img/3/7/vecteur.svg', '<svg/>');
        file_put_contents($this->uploads . '/img/3/7/script.php', '<?php echo 1;');
        file_put_contents(dirname($this->uploads) . '/kintai-secret-' . basename($this->uploads) . '.txt', 'secret');

        $this->controller = new StorageFileController($this->uploads);
    }

    protected function tearDown(): void
    {
        @unlink(dirname($this->uploads) . '/kintai-secret-' . basename($this->uploads) . '.txt');
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->uploads, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->uploads);
    }

    private function makeRequest(string $path, ?array $managedIds = null): Request
    {
        $request = new Request();
        $request->setRouteParams(['path' => $path]);
        $request->setAttribute('auth_user', ['id' => 1, 'is_admin' => $managedIds === null]);
        if ($managedIds !== null) {
            $request->setAttribute('managed_store_ids', $managedIds);
        }
        return $request;
    }

    public function testServesImageInlineForGlobalAdmin(): void
    {
        $response = $this->controller->serve($this->makeRequest('img/3/7/photo_1.jpg'));

        $this->assertSame(200, $response->status());
    }

    public function testRejectsPathTraversal(): void
    {
        $this->expectException(ForbiddenException::class);
        $this->controller->serve($this->makeRequest(
            '../kintai-secret-' . basename($this->uploads) . '.txt'
        ));
    }

    public function testRejectsDisallowedExtension(): void
    {
        $this->expectException(ForbiddenException::class);
        $this->controller->serve($this->makeRequest('img/3/7/script.php'));
    }

    public function testMissingFileThrowsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->controller->serve($this->makeRequest('img/3/7/absent.jpg'));
    }

    public function testManagerCanAccessOwnStoreFile(): void
    {
        $response = $this->controller->serve($this->makeRequest('img/3/7/photo_1.jpg', [3]));

        $this->assertSame(200, $response->status());
    }

    public function testManagerCannotAccessOtherStoreFile(): void
    {
        $this->expectException(ForbiddenException::class);
        $this->controller->serve($this->makeRequest('img/3/7/photo_1.jpg', [5]));
    }

    public function testSvgIsServedAsAttachment(): void
    {
        $response = $this->controller->serve($this->makeRequest('img/3/7/vecteur.svg'));

        $this->assertSame(200, $response->status());
        $reflection = new \ReflectionProperty($response, 'headers');
        $headers = $reflection->getValue($response);
        $this->assertStringContainsString('attachment', $headers['Content-Disposition']);
        $this->assertSame('nosniff', $headers['X-Content-Type-Options']);
    }
}
