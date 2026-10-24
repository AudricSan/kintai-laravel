<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core;

use kintai\Core\Container;
use kintai\Core\Exceptions\ValidationException;
use kintai\Core\OldInput;
use kintai\Core\Request;
use kintai\Core\SessionFlash;
use kintai\Core\ValidationFeedback;
use PHPUnit\Framework\TestCase;

/**
 * Erreur de validation d'un formulaire web (audit n°8, I6) : retour au formulaire avec le message précis et la saisie
 * conservée, au lieu d'une page 422 générique sans retour.
 */
final class ValidationFeedbackTest extends TestCase
{
    private array $server;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        $_POST = [];
        $_SESSION = [];
    }

    private function request(string $method, array $post, ?string $referer): Request
    {
        $_SERVER = ['REQUEST_METHOD' => $method, 'REQUEST_URI' => '/Kintai/admin/stores/1/edit', 'SCRIPT_NAME' => '/Kintai/index.php',
            'HTTP_HOST' => 'kintai.test'];
        if ($referer !== null) {
            $_SERVER['HTTP_REFERER'] = $referer;
        }
        $_POST = $post;
        $request = new Request();
        Container::getInstance()->instance(Request::class, $request);
        return $request;
    }

    public function testAFormGoesBackWithTheMessageAndTheInput(): void
    {
        $request = $this->request('POST', ['_token' => 'x', 'name' => 'Shibuya', 'slug' => 'Mauvais Slug', 'password' => 'secret'],
            'http://kintai.test/Kintai/admin/stores/1/edit');
        $e = new ValidationException(['slug' => 'URL du magasin invalide']);

        $response = ValidationFeedback::redirectBack($e, $request);

        $this->assertSame(303, $response?->status());
        $this->assertSame('http://kintai.test/Kintai/admin/stores/1/edit', $response->headers()['Location']);
        $this->assertSame([['type' => 'danger', 'text' => 'URL du magasin invalide']], SessionFlash::pull());
        $old = OldInput::pull();
        $this->assertSame('/Kintai/admin/stores/1/edit', $old['action']);
        $this->assertSame(['name' => 'Shibuya', 'slug' => 'Mauvais Slug'], $old['fields'], 'ni jeton ni mot de passe');
        $this->assertNull(OldInput::pull(), 'utilisée une seule fois');
    }

    public function testNoKnownOriginPageKeepsThe422Page(): void
    {
        $this->assertNull(ValidationFeedback::redirectBack(new ValidationException(['x' => 'm']), $this->request('POST', [], null)));
        $this->assertNull(ValidationFeedback::redirectBack(new ValidationException(['x' => 'm']),
            $this->request('POST', [], 'https://ailleurs.example/form')), 'page d\'un autre site');
    }

    public function testAReadRequestIsNotRedirected(): void
    {
        $this->assertNull(ValidationFeedback::redirectBack(new ValidationException(['x' => 'm']),
            $this->request('GET', [], 'http://kintai.test/Kintai/')));
    }

    public function testMessagesAreFlattenedWithoutDuplicates(): void
    {
        $e = new ValidationException(['a' => 'Un', 'b' => ['Deux', 'Un']]);
        $this->assertSame(['Un', 'Deux'], ValidationFeedback::messages($e));
        $this->assertSame([__('error_422_message')], ValidationFeedback::messages(new ValidationException([])));
    }

    public function testArraysKeepTheBrowserFieldNames(): void
    {
        OldInput::put('/f', ['days' => ['1', '3'], 'rates' => ['2' => '1200'], 'api_token' => 'x']);

        $this->assertSame(['days[]' => ['1', '3'], 'rates[2]' => '1200'], OldInput::pull()['fields']);
    }
}
