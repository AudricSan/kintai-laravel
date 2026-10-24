<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Contract;

use kintai\Contract\Contract;
use kintai\Contract\VersionConstraint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Contraintes de version du contrat de bundle (« kintai_contract » dans bundle.json), règles de Composer. */
final class VersionConstraintTest extends TestCase
{
    /** @return array<string, array{string, string, bool}> */
    public static function cases(): array
    {
        return [
            'caret majeur, borne basse'      => ['^1.0', '1.0.0', true],
            'caret majeur, dans la majeure'  => ['^1.0', '1.9.9', true],
            'caret majeur, majeure suivante' => ['^1.0', '2.0.0', false],
            'caret majeur, trop ancien'      => ['^1.2', '1.1.9', false],
            'caret 0.x'                      => ['^0.3', '0.3.9', true],
            'caret 0.x, mineure suivante'    => ['^0.3', '0.4.0', false],
            'caret 0.0.x'                    => ['^0.0.3', '0.0.4', false],
            'caret 0'                        => ['^0', '0.9.0', true],
            'tilde sans correctif'           => ['~1.2', '1.9.0', true],
            'tilde avec correctif'           => ['~1.2.3', '1.3.0', false],
            'intervalle'                     => ['>=1.0 <2.0', '1.5.0', true],
            'intervalle, hors borne'         => ['>=1.0 <2.0', '2.0.0', false],
            'ou'                             => ['^1.0 || ^2.0', '2.1.0', true],
            'ou, aucune'                     => ['^1.0 || ^3.0', '2.1.0', false],
            'joker'                          => ['*', '0.1.0', true],
            'exacte'                         => ['1.2', '1.2.0', true],
            'exacte, autre'                  => ['=1.2.3', '1.2.4', false],
            'préfixe v'                      => ['v1.2.3', '1.2.3', true],
        ];
    }

    #[DataProvider('cases')]
    public function testConstraints(string $constraint, string $version, bool $expected): void
    {
        $this->assertSame($expected, VersionConstraint::parse($constraint)?->allows($version));
    }

    /** @return array<string, array{string}> */
    public static function malformed(): array
    {
        return ['vide' => [''], 'texte' => ['latest'], 'opérateur seul' => ['^'], 'ou vide' => ['^1.0 ||'], 'version invalide' => ['^1.x']];
    }

    #[DataProvider('malformed')]
    public function testMalformedConstraintsAreRejected(string $constraint): void
    {
        $this->assertNull(VersionConstraint::parse($constraint));
    }

    public function testTheCoreSatisfiesItsOwnMajor(): void
    {
        $major = (int) explode('.', Contract::VERSION)[0];

        $this->assertTrue(Contract::satisfies('^' . $major . '.0'));
        $this->assertFalse(Contract::satisfies('^' . ($major + 1) . '.0'));
        $this->assertFalse(Contract::satisfies('n/a'));
    }
}
