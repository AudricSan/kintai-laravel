<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Audit du 01/10/2026 : les formulaires qui renvoient l'utilisateur d'où il vient (redirect_to, return_to)
 * acceptaient « /\site.example », que les navigateurs lisent comme « //site.example » (redirection ouverte).
 */
final class SafeRedirectPathTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function internalPaths(): array
    {
        return [
            'racine'                 => ['/'],
            'page avec paramètres'   => ['/admin/shifts?week=2026-W40&store=3'],
            'sous-dossier'           => ['/Kintai/public/admin/shifts/timeline'],
            'segment encodé'         => ['/admin/stores/%E6%89%80%E6%B2%A2/edit'],
            'ancre'                  => ['/admin/users#rates'],
        ];
    }

    #[DataProvider('internalPaths')]
    public function testInternalPathsAreKept(string $path): void
    {
        $this->assertSame($path, safe_redirect_path($path, '/fallback'));
    }

    /** @return array<string, array{string}> */
    public static function unsafePaths(): array
    {
        return [
            'vide'                        => [''],
            'URL absolue'                 => ['https://evil.example/'],
            'schéma javascript'           => ['javascript:alert(1)'],
            'relative au schéma'          => ['//evil.example/'],
            'antislash après la barre'    => ['/\\evil.example/'],
            'antislash en tête'           => ['\\evil.example'],
            'antislash ailleurs'          => ['/a\\b'],
            'tabulation (ignorée par les navigateurs)' => ["/\t/evil.example"],
            'saut de ligne'               => ["/admin\r\nLocation: https://evil.example"],
            'chemin relatif'              => ['admin/shifts'],
        ];
    }

    #[DataProvider('unsafePaths')]
    public function testUnsafeDestinationsFallBack(string $path): void
    {
        $this->assertSame('/fallback', safe_redirect_path($path, '/fallback'));
    }

    public function testNullFallsBack(): void
    {
        $this->assertSame('/fallback', safe_redirect_path(null, '/fallback'));
    }
}
