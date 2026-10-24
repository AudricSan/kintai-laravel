<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Middleware;

use kintai\Core\Middleware\SecurityHeadersMiddleware;
use kintai\Core\Request;
use kintai\Core\Response;
use PHPUnit\Framework\TestCase;

/**
 * La CSP n'autorise plus 'unsafe-inline' pour les scripts ni pour les styles : seuls les <script nonce="…"> et
 * <style nonce="…"> de la requête s'appliquent. Le nonce doit être imprévisible, propre à chaque requête, et identique entre l'en-tête
 * et ce que les vues écrivent (csp_nonce()).
 */
final class SecurityHeadersMiddlewareTest extends TestCase
{
    /** @return array{0: string, 1: string} [en-tête CSP, nonce vu par la vue pendant le rendu] */
    private function handleRequest(): array
    {
        $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'SCRIPT_NAME' => '/index.php'];
        $_GET = $_POST = $_COOKIE = $_FILES = [];

        $nonceSeenByView = '';
        $response = (new SecurityHeadersMiddleware())->handle(new Request(), function () use (&$nonceSeenByView): Response {
            $nonceSeenByView = csp_nonce();
            return Response::html('ok');
        });

        $ref = new \ReflectionProperty($response, 'headers');
        $ref->setAccessible(true);

        return [$ref->getValue($response)['Content-Security-Policy'] ?? '', $nonceSeenByView];
    }

    private function scriptSrc(string $csp): string
    {
        return $this->directive($csp, 'script-src');
    }

    private function directive(string $csp, string $name): string
    {
        preg_match('/(?:^|;\s*)' . preg_quote($name, '/') . ' ([^;]*)/', $csp, $m);

        return $m[1] ?? '';
    }

    public function testScriptsNoLongerAllowUnsafeInline(): void
    {
        [$csp] = $this->handleRequest();

        $this->assertStringNotContainsString("'unsafe-inline'", $this->scriptSrc($csp));
        $this->assertStringContainsString("'self'", $this->scriptSrc($csp));
    }

    public function testStylesNoLongerAllowUnsafeInline(): void
    {
        [$csp, $nonceInView] = $this->handleRequest();
        $styleSrc = $this->directive($csp, 'style-src');

        // Un attribut style="…" injecté ne s'applique plus ; les <style nonce> des layouts (style_class()) si.
        $this->assertStringNotContainsString("'unsafe-inline'", $styleSrc);
        $this->assertStringContainsString("'self'", $styleSrc);
        $this->assertStringContainsString("'nonce-{$nonceInView}'", $styleSrc);
    }

    public function testTheHeaderCarriesTheSameNonceAsTheViews(): void
    {
        [$csp, $nonceInView] = $this->handleRequest();

        $this->assertNotSame('', $nonceInView);
        $this->assertStringContainsString("'nonce-{$nonceInView}'", $this->scriptSrc($csp));
    }

    public function testNonceIsRenewedOnEveryRequest(): void
    {
        [, $first]  = $this->handleRequest();
        [, $second] = $this->handleRequest();

        $this->assertNotSame($first, $second, 'Réutiliser un nonce permettrait de le lire puis de le rejouer.');
    }

    public function testNonceIsUnpredictable(): void
    {
        [, $nonce] = $this->handleRequest();

        // 16 octets aléatoires en base64 : 24 caractères, alphabet base64 standard.
        $this->assertMatchesRegularExpression('#^[A-Za-z0-9+/]{22}==$#', $nonce);
    }

    public function testNonceIsStableWithinAOneRequest(): void
    {
        $this->handleRequest();

        $this->assertSame(csp_nonce(), csp_nonce());
    }

    public function testOtherDirectivesAreKept(): void
    {
        [$csp] = $this->handleRequest();

        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("frame-ancestors 'self'", $csp);
        $this->assertStringContainsString("form-action 'self' https://github.com", $csp);
    }
}
