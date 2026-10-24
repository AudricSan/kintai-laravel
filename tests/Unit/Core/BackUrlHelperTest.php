<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core;

use kintai\Core\Container;
use kintai\Core\Request;
use PHPUnit\Framework\TestCase;

/**
 * back_url() : principe de navigation contextuelle — une page atteignable depuis
 * plusieurs écrans ne doit pas avoir de destination "Retour" fixe. Voir helpers.php.
 */
final class BackUrlHelperTest extends TestCase
{
    private array $originalServer;

    protected function setUp(): void
    {
        $this->originalServer = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->originalServer;
    }

    private function makeRequest(string $uri, ?string $referer, string $host = 'kintai.example'): Request
    {
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['REQUEST_URI'] = $uri;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_HOST'] = $host;
        if ($referer !== null) {
            $_SERVER['HTTP_REFERER'] = $referer;
        } else {
            unset($_SERVER['HTTP_REFERER']);
        }

        $request = new Request();
        Container::getInstance()->instance(Request::class, $request);
        return $request;
    }

    public function testUsesSameOriginRefererWithItsFiltersAndPagination(): void
    {
        $this->makeRequest(
            '/admin/stores/1/reports/salary/30/edit',
            'http://kintai.example/admin/stores/1/reports/salary?year=2026&month=8&page=2',
        );

        $this->assertSame(
            'http://kintai.example/admin/stores/1/reports/salary?year=2026&month=8&page=2',
            back_url('http://kintai.example/admin/stores/1/reports/salary'),
        );
    }

    public function testFallsBackWhenNoRefererIsSent(): void
    {
        $this->makeRequest('/admin/stores/1/reports/salary/30/edit', null);

        $this->assertSame(
            'http://kintai.example/admin/stores/1/reports/salary',
            back_url('http://kintai.example/admin/stores/1/reports/salary'),
        );
    }

    public function testFallsBackWhenRefererIsCrossOrigin(): void
    {
        $this->makeRequest('/admin/stores/1/reports/salary/30/edit', 'https://attacker.example/phishing');

        $this->assertSame(
            'http://kintai.example/admin/stores/1/reports/salary',
            back_url('http://kintai.example/admin/stores/1/reports/salary'),
        );
    }

    public function testFallsBackWhenRefererIsTheCurrentPageItself(): void
    {
        // Ex : rechargement de la page d'édition — revenir "en arrière" vers elle-même serait inutile.
        $this->makeRequest(
            '/admin/stores/1/reports/salary/30/edit',
            'http://kintai.example/admin/stores/1/reports/salary/30/edit',
        );

        $this->assertSame(
            'http://kintai.example/admin/stores/1/reports/salary',
            back_url('http://kintai.example/admin/stores/1/reports/salary'),
        );
    }

    public function testStripsTheScriptBasePrefixBeforeComparingToTheCurrentUri(): void
    {
        // Hébergement dans un sous-dossier (ex: XAMPP htdocs/Kintai/public) : SCRIPT_NAME
        // porte un préfixe que Request retire de son uri() — back_url() doit faire pareil
        // pour comparer les mêmes chemins.
        $_SERVER['SCRIPT_NAME'] = '/Kintai/public/index.php';
        $_SERVER['REQUEST_URI'] = '/Kintai/public/admin/stores/1/reports/salary/30/edit';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_HOST'] = 'kintai.example';
        $_SERVER['HTTP_REFERER'] = 'http://kintai.example/Kintai/public/admin/stores/1/reports/salary?page=3';

        $request = new Request();
        Container::getInstance()->instance(Request::class, $request);

        $this->assertSame(
            'http://kintai.example/Kintai/public/admin/stores/1/reports/salary?page=3',
            back_url('http://kintai.example/Kintai/public/admin/stores/1/reports/salary'),
        );
    }
}
