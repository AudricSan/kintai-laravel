<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Controller\Web;

use kintai\Core\Request;
use kintai\UI\Controller\Web\LegalController;
use kintai\UI\ViewRenderer;
use PHPUnit\Framework\TestCase;

final class LegalControllerTest extends TestCase
{
    private string $tmpViews;
    private LegalController $controller;

    protected function setUp(): void
    {
        $this->tmpViews = sys_get_temp_dir() . '/kintai_legalctrl_views_' . uniqid();
        mkdir($this->tmpViews . '/legal', 0755, true);
        mkdir($this->tmpViews . '/layout', 0755, true);

        file_put_contents($this->tmpViews . '/legal/mentions.php', 'MENTIONS');
        file_put_contents($this->tmpViews . '/legal/terms.php', 'TERMS');
        file_put_contents($this->tmpViews . '/legal/license.php', 'LICENSE');
        file_put_contents($this->tmpViews . '/layout/app.php', '<?= $content ?>');

        $view = new ViewRenderer($this->tmpViews);
        $this->controller = new LegalController($view);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmpViews, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->tmpViews);
    }

    public function testMentionsRendersMentionsView(): void
    {
        $response = $this->controller->mentions(new Request());

        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('MENTIONS', $response->body());
    }

    public function testTermsRendersTermsView(): void
    {
        $response = $this->controller->terms(new Request());

        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('TERMS', $response->body());
    }

    public function testLicenseRendersLicenseView(): void
    {
        $response = $this->controller->license(new Request());

        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('LICENSE', $response->body());
    }
}
