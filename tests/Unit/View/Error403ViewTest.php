<?php

declare(strict_types=1);

namespace Tests\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * La vue errors/403.php remplace le fallback HTML brut d'Application::renderError
 * pour les ForbiddenException (notamment celles de PermissionMiddleware,
 * message « Permission requise : <clé> »).
 */
final class Error403ViewTest extends TestCase
{
    private function render(?string $message): string
    {
        $BASE_URL = '';
        ob_start();
        include \App\View\ViewRenderer::pathInScope('errors.403', (new \App\View\ViewRenderer(__DIR__ . '/../../../resources/views'))->factory());
        return ob_get_clean();
    }

    public function testRendersCodeGenericMessageAndBackLink(): void
    {
        $html = $this->render(null);

        $this->assertStringContainsString('error-code', $html);
        $this->assertStringContainsString('403', $html);
        $this->assertStringContainsString('error-link', $html);
    }

    public function testShowsTechnicalDetailWhenProvided(): void
    {
        $html = $this->render('Permission requise : shifts.import');

        $this->assertStringContainsString('error-detail', $html);
        $this->assertStringContainsString('Permission requise : shifts.import', $html);
    }

    public function testEscapesTechnicalDetail(): void
    {
        $html = $this->render('<script>alert(1)</script>');

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testOmitsDetailBlockWhenNoMessage(): void
    {
        $html = $this->render(null);

        $this->assertStringNotContainsString('error-detail', $html);
    }
}
