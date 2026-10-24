<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Controller\Web;

use kintai\Core\Request;
use kintai\UI\Controller\Web\PwaController;
use PHPUnit\Framework\TestCase;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 4));
}

final class PwaControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        $_SERVER = [];
    }

    public function testManifestExposesInstallableAndMaskableIcons(): void
    {
        $_SERVER['HTTP_HOST']   = 'kintai.example';
        $_SERVER['SCRIPT_NAME'] = '/index.php';

        $response = (new PwaController())->manifest(new Request());
        $data     = json_decode($response->body(), true);

        $this->assertSame('standalone', $data['display']);

        $purposes = array_column($data['icons'], 'purpose');
        $this->assertContains('any', $purposes);
        $this->assertContains('maskable', $purposes);

        foreach ($data['icons'] as $icon) {
            $this->assertStringEndsWith('.png', $icon['src']);
            $this->assertSame('image/png', $icon['type']);
        }
    }

    public function testServiceWorkerInjectsAssetVersionAsCacheName(): void
    {
        $response = (new PwaController())->serviceWorker(new Request());

        $reflection = new \ReflectionProperty($response, 'headers');
        $headers = $reflection->getValue($response);
        $this->assertSame('application/javascript; charset=UTF-8', $headers['Content-Type']);

        $this->assertStringContainsString("const CACHE = 'kintai-" . asset_version() . "';", $response->body());
        $this->assertStringNotContainsString('__ASSET_VERSION__', $response->body());
    }
}
