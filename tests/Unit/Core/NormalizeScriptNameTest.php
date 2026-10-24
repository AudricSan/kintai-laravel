<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Le .htaccess racine réécrit en interne vers public/ : Apache renseigne alors SCRIPT_NAME avec
 * « /…/public/index.php » alors que le visiteur n'a jamais tapé « /public ». kintai_normalize_script_name()
 * remet SCRIPT_NAME d'aplomb pour que base_url() et le routeur voient l'URL réelle.
 */
final class NormalizeScriptNameTest extends TestCase
{
    private array $server;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
    }

    /** @return array{string, string} [SCRIPT_NAME, PHP_SELF] après normalisation */
    private function normalize(string $scriptName, string $requestUri): array
    {
        $_SERVER['SCRIPT_NAME'] = $scriptName;
        $_SERVER['PHP_SELF']    = $scriptName;
        $_SERVER['REQUEST_URI'] = $requestUri;

        kintai_normalize_script_name();

        return [$_SERVER['SCRIPT_NAME'], $_SERVER['PHP_SELF']];
    }

    #[DataProvider('rewritten')]
    public function testRewrittenRequestDropsPublicSegment(string $scriptName, string $uri, string $expected): void
    {
        $this->assertSame([$expected, $expected], $this->normalize($scriptName, $uri));
        $this->assertStringNotContainsString('/public', base_url(), 'base_url() ne doit plus contenir /public');
    }

    /** @return array<string, array{string, string, string}> */
    public static function rewritten(): array
    {
        return [
            'domaine sur la racine du dépôt' => ['/public/index.php', '/login', '/index.php'],
            'avec une query string'          => ['/public/index.php', '/shifts?week=3', '/index.php'],
            'sous-dossier (htdocs/Kintai)'   => ['/Kintai/public/index.php', '/Kintai/login', '/Kintai/index.php'],
            'sous-dossier, racine du site'   => ['/Kintai/public/index.php', '/Kintai/', '/Kintai/index.php'],
            'installeur'                     => ['/Kintai/public/install.php', '/Kintai/install.php', '/Kintai/install.php'],
        ];
    }

    #[DataProvider('untouched')]
    public function testOtherSetupsAreLeftAlone(string $scriptName, string $uri): void
    {
        $this->assertSame([$scriptName, $scriptName], $this->normalize($scriptName, $uri));
    }

    /** @return array<string, array{string, string}> */
    public static function untouched(): array
    {
        return [
            'public/ est le DocumentRoot'             => ['/index.php', '/login'],
            'DocumentRoot, sous-dossier'              => ['/Kintai/index.php', '/Kintai/login'],
            'accès direct par /public (ancien usage)' => ['/Kintai/public/index.php', '/Kintai/public/login'],
            'accès direct, domaine sur la racine'     => ['/public/index.php', '/public/login'],
            'accès direct à la racine de public'      => ['/public/index.php', '/public'],
        ];
    }

    public function testDoesNotConfuseNeighbourFolderWithPublicPrefix(): void
    {
        // « /publication » commence par « /public » sans être dans /public : la requête a bien été réécrite.
        $this->assertSame(
            ['/index.php', '/index.php'],
            $this->normalize('/public/index.php', '/publication'),
        );
    }
}
