<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Exceptions\ValidationException;
use App\Http\ErrorResponder;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

/** Erreur de validation d'un formulaire web : retour au formulaire avec erreurs et saisie flashées (sans secrets). */
final class ValidationRedirectTest extends TestCase
{
    protected function setUp(): void
    {
        session()->flush();
    }

    private function post(array $post, ?string $referer): Request
    {
        $request = Request::create('http://kintai.test/admin/stores/1/edit', 'POST', $post);
        $referer !== null && $request->headers->set('Referer', $referer);
        app()->instance('request', $request);

        return $request;
    }

    public function testAFormGoesBackWithTheMessagesAndTheInputWithoutSecrets(): void
    {
        $request  = $this->post(['_token' => 'x', 'name' => 'Shibuya', 'slug' => 'Mauvais Slug', 'password' => 'secret'], 'http://kintai.test/admin/stores/1/edit');
        $response = ErrorResponder::render(new ValidationException(['slug' => 'URL du magasin invalide']), $request);

        $this->assertSame(303, $response->getStatusCode());
        $this->assertSame('http://kintai.test/admin/stores/1/edit', $response->headers->get('Location'));
        $session = $response->getSession();
        $this->assertSame(['URL du magasin invalide'], $session->get('errors')->all());
        $this->assertSame(['name' => 'Shibuya', 'slug' => 'Mauvais Slug'], $session->get('_old_input'));
    }

    public function testWithoutAKnownOriginTheErrorPageShowsThePreciseMessage(): void
    {
        $response = ErrorResponder::render(new ValidationException(['slug' => 'URL du magasin invalide']), $this->post(['name' => 'x'], null));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('URL du magasin invalide', (string) $response->getContent());
    }
}