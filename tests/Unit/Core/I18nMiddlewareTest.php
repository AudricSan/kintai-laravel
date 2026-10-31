<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Http\Middleware\I18nMiddleware;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class I18nMiddlewareTest extends TestCase
{
    private ?I18nMiddleware $middleware = null;

    protected function setUp(): void
    {
    }

    /** Objet testé, construit à la première utilisation : un test peut d'abord remplacer un bouchon par un mock. */
    private function middleware(): I18nMiddleware
    {
        if ($this->middleware === null) {
            $this->middleware = new I18nMiddleware();
        }

        return $this->middleware;
    }

    protected function tearDown(): void
    {
        session()->forget(['locale', auth()->getName()]);
        unset($_ENV['APP_LOCALE']);
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

        return Request::capture();
    }

    private function noop(): \Closure
    {
        return fn(Request $req) => response()->json(['ok' => true]);
    }

    public function testNoActiveLanguagesFallsBackToEnglish(): void
    {
        kintai_test_languages([]);

        $this->middleware()->handle($this->makeRequest(), $this->noop());

        $this->assertSame('en', app()->getLocale());
    }

    public function testSessionLocaleUsedWhenActive(): void
    {
        kintai_test_languages(['fr', 'en']);
        session()->put('locale', 'fr');

        $this->middleware()->handle($this->makeRequest(), $this->noop());

        $this->assertSame('fr', app()->getLocale());
    }

    public function testSessionLocaleIgnoredWhenLanguageInactive(): void
    {
        kintai_test_languages(['en']);
        session()->put('locale', 'fr'); // fr désactivée entre-temps par l'Owner

        $this->middleware()->handle($this->makeRequest(), $this->noop());

        $this->assertSame('en', app()->getLocale());
    }

    public function testLoggedInUserGetsTheProfileLanguageBeforeSessionCookieAndBrowser(): void
    {
        kintai_test_languages(['fr', 'ja', 'en']);
        kintai_test_users(findById: fn(int $id) => $id === 1 ? ['id' => 1, 'language' => 'ja'] : null);
        $req = $this->makeRequest(['HTTP_ACCEPT_LANGUAGE' => 'en-US']);
        session()->put(auth()->getName(), 1);
        session()->put('locale', 'fr');
        $_COOKIE['kintai_locale'] = 'en';

        $this->middleware()->handle($req, $this->noop());

        $this->assertSame('ja', app()->getLocale());
    }

    public function testLoggedInUserWithoutLanguageFollowsTheInstanceNotTheBrowser(): void
    {
        kintai_test_languages(['fr', 'ja', 'en']);
        kintai_test_users(findById: fn() => ['id' => 1, 'language' => '']);
        $_ENV['APP_LOCALE'] = 'ja';

        $req = $this->makeRequest(['HTTP_ACCEPT_LANGUAGE' => 'en-US']);
        session()->put(auth()->getName(), 1);

        $this->middleware()->handle($req, $this->noop());

        $this->assertSame('ja', app()->getLocale());
    }

    public function testLastAccountLanguageCookieComesBeforeTheBrowserOnTheLoginPage(): void
    {
        kintai_test_languages(['fr', 'ja', 'en']);

        $req = $this->makeRequest(['HTTP_ACCEPT_LANGUAGE' => 'en-US']);
        $_COOKIE['kintai_locale'] = 'ja';

        $this->middleware()->handle($req, $this->noop());

        $this->assertSame('ja', app()->getLocale());
    }

    public function testBrowserLanguageUsedWhenActive(): void
    {
        kintai_test_languages(['fr', 'ja']);

        $req = $this->makeRequest(['HTTP_ACCEPT_LANGUAGE' => 'ja-JP,ja;q=0.9']);

        $this->middleware()->handle($req, $this->noop());

        $this->assertSame('ja', app()->getLocale());
    }

    public function testInstanceLanguageWinsOverTheLegacyDefault(): void
    {
        kintai_test_languages(['fr', 'en']);
        $_ENV['APP_LOCALE'] = 'en';

        $this->middleware()->handle($this->makeRequest(), $this->noop());

        $this->assertSame('en', app()->getLocale());
    }

    public function testDefaultLocaleUsedAsUltimateFallback(): void
    {
        kintai_test_languages(['fr', 'en']);

        $req = $this->makeRequest(['HTTP_ACCEPT_LANGUAGE' => 'de-DE,de;q=0.9']); // 'de' non actif

        $this->middleware()->handle($req, $this->noop());

        $this->assertSame('fr', app()->getLocale());
    }
}
