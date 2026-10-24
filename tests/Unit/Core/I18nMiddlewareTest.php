<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core;

use kintai\Core\Middleware\I18nMiddleware;
use kintai\Core\Repositories\LanguageRepositoryInterface;
use kintai\Core\Repositories\TranslationRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Response;
use kintai\Core\Services\TranslationService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class I18nMiddlewareTest extends TestCase
{
    private LanguageRepositoryInterface&Stub $languages;
    private TranslationService $translationService;
    private ?I18nMiddleware $middleware = null;
    private UserRepositoryInterface&Stub $users;

    protected function setUp(): void
    {
        $this->languages = $this->createStub(LanguageRepositoryInterface::class);

        $translationRepo = $this->createStub(TranslationRepositoryInterface::class);
        $translationRepo->method('findByLocale')->willReturn([]);

        $this->translationService = new TranslationService($translationRepo, $this->languages);
        $this->users = $this->createStub(UserRepositoryInterface::class);
    }

    /** Objet testé, construit à la première utilisation : un test peut d'abord remplacer un bouchon par un mock. */
    private function middleware(): I18nMiddleware
    {
        if ($this->middleware === null) {
            $this->middleware = new I18nMiddleware($this->translationService, $this->languages, $this->users);
        }

        return $this->middleware;
    }

    protected function tearDown(): void
    {
        unset($_SESSION['locale'], $_SESSION['auth_user_id'], $_ENV['APP_LOCALE']);
        $_COOKIE = [];
    }

    private function makeRequest(array $server = []): Request
    {
        $_SERVER = array_merge([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI'    => '/',
            'SCRIPT_NAME'    => '/index.php',
        ], $server);
        $_GET = [];
        $_POST = [];
        $_COOKIE = [];
        $_FILES = [];

        return new Request();
    }

    private function noop(): \Closure
    {
        return fn(Request $req) => Response::json(['ok' => true]);
    }

    public function testNoActiveLanguagesFallsBackToEnglish(): void
    {
        $this->languages->method('findAllActive')->willReturn([]);

        $this->middleware()->handle($this->makeRequest(), $this->noop());

        $this->assertSame('en', $this->translationService->getLocale());
    }

    public function testSessionLocaleUsedWhenActive(): void
    {
        $this->languages->method('findAllActive')->willReturn([['code' => 'fr'], ['code' => 'en']]);
        $_SESSION['locale'] = 'fr';

        $this->middleware()->handle($this->makeRequest(), $this->noop());

        $this->assertSame('fr', $this->translationService->getLocale());
    }

    public function testSessionLocaleIgnoredWhenLanguageInactive(): void
    {
        $this->languages->method('findAllActive')->willReturn([['code' => 'en']]);
        $this->languages->method('findDefault')->willReturn(['code' => 'en']);
        $_SESSION['locale'] = 'fr'; // fr désactivée entre-temps par l'Owner

        $this->middleware()->handle($this->makeRequest(), $this->noop());

        $this->assertSame('en', $this->translationService->getLocale());
    }

    public function testLoggedInUserGetsTheProfileLanguageBeforeSessionCookieAndBrowser(): void
    {
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $this->languages->method('findAllActive')->willReturn([['code' => 'fr'], ['code' => 'ja'], ['code' => 'en']]);
        $this->users->expects($this->atLeastOnce())->method('findById')->with(1)->willReturn(['id' => 1, 'language' => 'ja']);

        $req = $this->makeRequest(['HTTP_ACCEPT_LANGUAGE' => 'en-US']);
        $_SESSION['auth_user_id'] = 1;
        $_SESSION['locale'] = 'fr';
        $_COOKIE['kintai_locale'] = 'en';

        $this->middleware()->handle($req, $this->noop());

        $this->assertSame('ja', $this->translationService->getLocale());
    }

    public function testLoggedInUserWithoutLanguageFollowsTheInstanceNotTheBrowser(): void
    {
        $this->languages->method('findAllActive')->willReturn([['code' => 'fr'], ['code' => 'ja'], ['code' => 'en']]);
        $this->users->method('findById')->willReturn(['id' => 1, 'language' => '']);
        $_ENV['APP_LOCALE'] = 'ja';

        $req = $this->makeRequest(['HTTP_ACCEPT_LANGUAGE' => 'en-US']);
        $_SESSION['auth_user_id'] = 1;

        $this->middleware()->handle($req, $this->noop());

        $this->assertSame('ja', $this->translationService->getLocale());
    }

    public function testLastAccountLanguageCookieComesBeforeTheBrowserOnTheLoginPage(): void
    {
        $this->languages->method('findAllActive')->willReturn([['code' => 'fr'], ['code' => 'ja'], ['code' => 'en']]);

        $req = $this->makeRequest(['HTTP_ACCEPT_LANGUAGE' => 'en-US']);
        $_COOKIE['kintai_locale'] = 'ja';

        $this->middleware()->handle($req, $this->noop());

        $this->assertSame('ja', $this->translationService->getLocale());
    }

    public function testBrowserLanguageUsedWhenActive(): void
    {
        $this->languages->method('findAllActive')->willReturn([['code' => 'fr'], ['code' => 'ja']]);

        $req = $this->makeRequest(['HTTP_ACCEPT_LANGUAGE' => 'ja-JP,ja;q=0.9']);

        $this->middleware()->handle($req, $this->noop());

        $this->assertSame('ja', $this->translationService->getLocale());
    }

    public function testInstanceLanguageWinsOverTheLegacyDefault(): void
    {
        $this->languages->method('findAllActive')->willReturn([['code' => 'fr'], ['code' => 'en']]);
        $this->languages->method('findDefault')->willReturn(['code' => 'fr']);
        $_ENV['APP_LOCALE'] = 'en';

        $this->middleware()->handle($this->makeRequest(), $this->noop());

        $this->assertSame('en', $this->translationService->getLocale());
    }

    public function testDefaultLocaleUsedAsUltimateFallback(): void
    {
        $this->languages->method('findAllActive')->willReturn([['code' => 'fr'], ['code' => 'en']]);
        $this->languages->method('findDefault')->willReturn(['code' => 'fr']);

        $req = $this->makeRequest(['HTTP_ACCEPT_LANGUAGE' => 'de-DE,de;q=0.9']); // 'de' non actif

        $this->middleware()->handle($req, $this->noop());

        $this->assertSame('fr', $this->translationService->getLocale());
    }
}
