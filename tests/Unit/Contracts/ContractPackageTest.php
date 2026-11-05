<?php

namespace Tests\Unit\Contracts;

use FilesystemIterator;
use InvalidArgumentException;
use Kintai\Contracts\Contract;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Le package `kintai/contracts` doit rester autonome : un bundle ne tire jamais le Core par le contrat.
 */
class ContractPackageTest extends TestCase
{
    private const ROOT = __DIR__.'/../../../packages/contracts';

    /**
     * @return list<array{string, string}> [chemin, contenu]
     */
    private function sources(): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::ROOT.'/src', FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = [$file->getPathname(), (string) file_get_contents($file->getPathname())];
            }
        }

        $this->assertNotEmpty($files);

        return $files;
    }

    public function test_the_contract_never_references_the_application(): void
    {
        $offenders = [];

        foreach ($this->sources() as [$path, $code]) {
            if (preg_match('/(?<![\w\\\\])\\\\?App\\\\/', $code) === 1) {
                $offenders[] = basename($path);
            }
        }

        $this->assertSame([], $offenders, 'The contract must not depend on App\\.');
    }

    public function test_the_contract_only_uses_the_illuminate_components_it_requires(): void
    {
        $composer = json_decode((string) file_get_contents(self::ROOT.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $required = array_map(
            fn (string $package): string => ucfirst(substr($package, strlen('illuminate/'))),
            array_filter(array_keys($composer['require']), fn (string $package): bool => str_starts_with($package, 'illuminate/')),
        );

        $this->assertNotContains('laravel/framework', array_keys($composer['require']));

        $used = [];
        foreach ($this->sources() as [, $code]) {
            preg_match_all('/Illuminate\\\\(\w+)\\\\/', $code, $matches);
            array_push($used, ...$matches[1]);
        }

        // Les façades (Event, Route, Gate, Request) sont dans `illuminate/support` et s'appuient sur le conteneur de l'application hôte.
        $this->assertSame([], array_values(array_diff(array_unique($used), $required)), 'Undeclared Illuminate component used by the contract.');
    }

    public function test_the_package_manifest_matches_the_contract_version_and_the_license_of_the_application(): void
    {
        $contracts = json_decode((string) file_get_contents(self::ROOT.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $root = json_decode((string) file_get_contents(self::ROOT.'/../../composer.json'), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('kintai/contracts', $contracts['name']);
        $this->assertSame(Contract::VERSION, $contracts['version']);
        $this->assertSame($root['license'], $contracts['license']);
        $this->assertSame(['Kintai\\Contracts\\' => 'src/'], $contracts['autoload']['psr-4']);
        $this->assertSame('^'.Contract::major().'.0', $root['require']['kintai/contracts']);
    }

    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function constraints(): array
    {
        return [
            'caret same major' => ['^1.0', '1.4.2', true],
            'caret below the floor' => ['^1.2', '1.1.9', false],
            'caret next major' => ['^2.0', '1.9.9', false],
            'caret previous major' => ['^1.0', '2.0.0', false],
            'wildcard' => ['1.*', '1.7.0', true],
            'wildcard other major' => ['2.*', '1.7.0', false],
            'tilde' => ['~1.2', '1.9.0', true],
            'tilde patch' => ['~1.2.3', '1.3.0', false],
            'exact' => ['1.0.0', '1.0.0', true],
            'exact other' => ['1.0.1', '1.0.0', false],
            'range' => ['>=1.0 <2.0', '1.5.0', true],
            'range excluded' => ['>=1.0 <2.0', '2.0.0', false],
            'alternatives' => ['^0.9 || ^1.0', '1.0.0', true],
            'alternatives none' => ['^0.9 || ^2.0', '1.0.0', false],
        ];
    }

    #[DataProvider('constraints')]
    public function test_the_contract_version_constraints(string $constraint, string $version, bool $expected): void
    {
        $this->assertSame($expected, Contract::satisfies($constraint, $version));
    }

    public function test_the_installed_contract_satisfies_its_own_major(): void
    {
        $this->assertTrue(Contract::satisfies('^'.Contract::major().'.0'));
        $this->assertFalse(Contract::satisfies('^'.(Contract::major() + 1).'.0'));
    }

    public function test_an_unsupported_constraint_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Contract::satisfies('dev-main');
    }
}
