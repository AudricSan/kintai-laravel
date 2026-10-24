<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core;

use kintai\Core\Application;
use kintai\Core\Container;
use kintai\Core\Exceptions\HttpException;
use kintai\Core\Request;
use PHPUnit\Framework\TestCase;

/**
 * HttpException porte des en-têtes (ex. Retry-After d'un 429) : Application::handleException() les
 * ignorait, si bien que le client ne savait jamais combien de temps attendre.
 */
final class ApplicationHttpExceptionHeadersTest extends TestCase
{
    protected function tearDown(): void
    {
        $_SERVER = [];
    }

    private function handle(HttpException $e): \kintai\Core\Response
    {
        $_SERVER = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/api/v1/auth/login', 'SCRIPT_NAME' => '/index.php', 'HTTP_ACCEPT' => 'application/json'];
        $request = new Request();
        // Réponse JSON : évite de devoir construire le moteur de vues pour ce test.
        $request->setAttribute('wantsJson', true);

        $app = (new \ReflectionClass(Application::class))->newInstanceWithoutConstructor();
        $container = new \ReflectionProperty(Application::class, 'container');
        $container->setAccessible(true);
        $container->setValue($app, new Container());

        $method = new \ReflectionMethod(Application::class, 'handleException');
        $method->setAccessible(true);

        return $method->invoke($app, $e, $request);
    }

    public function testHttpExceptionHeadersAreAppliedToTheResponse(): void
    {
        $response = $this->handle(new HttpException(429, 'Trop de tentatives.', ['Retry-After' => '120']));

        $this->assertSame(429, $response->status());
        $this->assertSame('120', $this->headerOf($response, 'Retry-After'));
    }

    public function testHttpExceptionWithoutHeadersStillWorks(): void
    {
        $response = $this->handle(new HttpException(403, 'Interdit'));

        $this->assertSame(403, $response->status());
    }

    private function headerOf(\kintai\Core\Response $response, string $name): ?string
    {
        $prop = new \ReflectionProperty($response, 'headers');
        $prop->setAccessible(true);
        return $prop->getValue($response)[$name] ?? null;
    }
}
