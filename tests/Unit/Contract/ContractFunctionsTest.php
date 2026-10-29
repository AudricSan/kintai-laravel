<?php

declare(strict_types=1);

namespace Tests\Unit\Contract;

use Tests\Support\ContractUsageScanner;
use PHPUnit\Framework\TestCase;

/**
 * Fonctions globales du contrat (app/Contract/functions.php) : chaque fonction déclarée existe avec la signature
 * promise, et toute fonction globale du Core est soit déclarée, soit explicitement interne.
 */
final class ContractFunctionsTest extends TestCase
{
    /** @return array{declared: array<string, array>, internal: list<string>} */
    private static function functions(): array
    {
        return require dirname(__DIR__, 3) . '/app/Contract/functions.php';
    }

    public function testEveryDeclaredFunctionExistsWithTheSignaturePromised(): void
    {
        foreach (self::functions()['declared'] as $name => $entry) {
            $this->assertTrue(function_exists($name), "$name est déclarée au contrat mais n'existe pas");
            $reflection = new \ReflectionFunction($name);
            $this->assertSame($entry['params'], array_map(fn(\ReflectionParameter $p) => $p->getName(), $reflection->getParameters()), "paramètres de $name");
            $this->assertSame($entry['returns'], (string) $reflection->getReturnType(), "type de retour de $name");
            $this->assertContains($entry['status'], ['stable', 'provisoire'], $name);
            $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $entry['since'], $name);
            $this->assertNotSame('', trim($entry['role']), $name);
        }
    }

    /** Un nouveau helper dans les fichiers de fonctions du Core force une décision : contrat ou interne. */
    public function testEveryGlobalFunctionOfTheCoreIsDeclaredOrInternal(): void
    {
        $known = array_merge(array_keys(self::functions()['declared']), self::functions()['internal']);
        $defined = [];
        foreach (['app/Support/helpers.php', 'app/helpers.php'] as $file) {
            preg_match_all('/^\s*function\s+(\w+)\s*\(/m', (string) file_get_contents(dirname(__DIR__, 3) . '/' . $file), $m);
            array_push($defined, ...$m[1]);
        }

        $this->assertNotEmpty($defined);
        $this->assertSame([], array_values(array_diff($defined, $known)), 'fonction(s) à ranger dans app/Contract/functions.php (declared ou internal)');
    }

    public function testNoFunctionIsBothDeclaredAndInternal(): void
    {
        $this->assertSame([], array_values(array_intersect(array_keys(self::functions()['declared']), self::functions()['internal'])));
    }

    public function testScannerFlagsInternalCoreFunctionsAndAcceptsDeclaredOnes(): void
    {
        $dir = sys_get_temp_dir() . '/kintai-fn-scan-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/ok.php', "<?php\necho __('x'), csrf_field(), strtoupper('a'), route_url('r');\nfunction local_helper() { return 1; }\necho local_helper();\n\$o->hash_token(); Foo::env();\n");
        file_put_contents($dir . '/bad.php', "<?php\necho hash_token('a');\n\$x = asset_version();\n");

        try {
            $violations = ContractUsageScanner::functionViolations([$dir]);
        } finally {
            unlink($dir . '/ok.php');
            unlink($dir . '/bad.php');
            rmdir($dir);
        }

        $this->assertCount(2, $violations);
        $this->assertStringContainsString('bad.php:2 : hash_token', $violations[0]);
        $this->assertStringContainsString('bad.php:3 : asset_version', $violations[1]);
    }
}
