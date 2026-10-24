<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core\Services\BundleInstaller;

use kintai\Core\Services\BundleInstaller\BundleInstallerService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Audit n°7 : slug et version arrivent en POST et deviennent des noms de dossier sous storage/bundles/ ;
 * ils doivent être validés avant tout chemin disque.
 */
final class BundleInstallerIdentityTest extends TestCase
{
    #[DataProvider('slugs')]
    public function testSlugValidation(string $slug, bool $valid): void
    {
        $this->assertSame($valid, BundleInstallerService::isValidSlug($slug));
    }

    /** @return array<string, array{string, bool}> */
    public static function slugs(): array
    {
        return [
            'simple'              => ['timeoff', true],
            'avec tirets'         => ['shift-swap', true],
            'avec chiffres'       => ['bundle2', true],
            'vide'                => ['', false],
            'remontée de dossier' => ['../..', false],
            'sous-dossier'        => ['a/b', false],
            'antislash'           => ['a\\b', false],
            'majuscules'          => ['TimeOff', false],
            'point'               => ['a.b', false],
            'tiret en tête'       => ['-a', false],
            'trop long'           => [str_repeat('a', 64), false],
            'octet nul'           => ["a\0b", false],
        ];
    }

    #[DataProvider('versions')]
    public function testVersionValidation(string $version, bool $valid): void
    {
        $this->assertSame($valid, BundleInstallerService::isValidVersion($version));
    }

    /** @return array<string, array{string, bool}> */
    public static function versions(): array
    {
        return [
            'semver'              => ['1.2.3', true],
            'pré-version'         => ['1.2.3-alpha.1', true],
            'vide'                => ['', false],
            'remontée de dossier' => ['..', false],
            'chemin'              => ['1.2.3/../../x', false],
            'deux composantes'    => ['1.2', false],
            'lettres'             => ['v1.2.3', false],
            'antislash'           => ['1.2.3\\x', false],
        ];
    }
}
