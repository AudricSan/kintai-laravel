<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Http;

use Illuminate\Http\Request;
use kintai\Core\Exceptions\HttpException;
use kintai\Core\Exceptions\MethodNotAllowedException;
use kintai\Http\ErrorResponder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Rendu des exceptions (ErrorResponder::render(), branché dans withExceptions()) : HttpException porte des en-têtes
 * (ex. Retry-After d'un 429), un 404/405 de Laravel est rendu comme ceux de Kintai, une erreur de validation de
 * Laravel comme une validation Kintai.
 */
final class ErrorResponderTest extends TestCase
{
    private function render(\Throwable $e, string $method = 'POST', string $uri = '/api/v1/auth/login'): \Symfony\Component\HttpFoundation\Response
    {
        // Réponse JSON (chemin d'API) : évite de devoir construire le moteur de vues pour ce test.
        return ErrorResponder::render($e, Request::create($uri, $method, [], [], [], ['HTTP_ACCEPT' => 'application/json']));
    }

    public function testHttpExceptionHeadersAreAppliedToTheResponse(): void
    {
        $response = $this->render(new HttpException(429, 'Trop de tentatives.', ['Retry-After' => '120']));

        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame('120', $response->headers->get('Retry-After'));
    }

    public function testHttpExceptionWithoutHeadersStillWorks(): void
    {
        $this->assertSame(403, $this->render(new HttpException(403, 'Interdit'))->getStatusCode());
    }

    public function testAnUnknownRouteIsA404WithKintaisMessage(): void
    {
        $response = $this->render(new NotFoundHttpException('The route nope could not be found.'), 'GET', '/nope');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(['error' => 'No route matches [GET /nope].'], json_decode($response->getContent(), true));
    }

    public function testAWrongMethodIsA405WithTheAllowHeader(): void
    {
        $response = $this->render(new MethodNotAllowedException(['GET', 'POST']));

        $this->assertSame(405, $response->getStatusCode());
        $this->assertSame('GET, POST', $response->headers->get('Allow'));
    }

    public function testALaravelValidationErrorIsAnsweredLikeAKintaiOne(): void
    {
        $validator = new \Illuminate\Validation\Validator(kintai_test_translator(), [], []);
        $validator->errors()->merge([
            'name' => ['Nom obligatoire.', 'Autre message.'],
            'mail' => ['Adresse invalide.'],
        ]);

        $response = $this->render(new \Illuminate\Validation\ValidationException($validator));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(
            ['error' => 'Nom obligatoire.', 'errors' => ['name' => 'Nom obligatoire.', 'mail' => 'Adresse invalide.']],
            json_decode($response->getContent(), true),
        );
    }

    public function testAnUnexpectedErrorIsAGenericJson500(): void
    {
        $response = $this->render(new \RuntimeException('secret détail'));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringNotContainsString('secret', (string) $response->getContent());
    }

    public function testAResponseBuiltByTheCallerIsLeftAlone(): void
    {
        $this->assertNull(ErrorResponder::render(
            new \Illuminate\Http\Exceptions\HttpResponseException(response('x')),
            Request::create('/x'),
        ));
    }
}
