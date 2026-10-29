<?php

declare(strict_types=1);

namespace Tests\Unit\Controller\Web;

use Illuminate\Http\Request;
use App\Http\Controllers\PwaController;
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

        $response = (new PwaController())->manifest(Request::capture());
        $data     = json_decode($response->getContent(), true);

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
        $response = (new PwaController())->serviceWorker(Request::capture());

        $headers = kintai_test_headers($response);
        $this->assertSame('application/javascript; charset=UTF-8', $headers['Content-Type']);

        $this->assertStringContainsString("const CACHE = 'kintai-" . asset_version() . "';", $response->getContent());
        $this->assertStringNotContainsString('__ASSET_VERSION__', $response->getContent());
    }
}
