<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

/**
 * scripts/set-release-version.php écrit la version calculée par release.yml dans config/app.php du commit taggé.
 * Sans lui, l'archive d'une release affirme la version littérale figée dans le dépôt et l'installateur de bundles
 * (kintai_core.min) refuse les bundles qui exigent une version plus récente.
 */
final class SetReleaseVersionScriptTest extends TestCase
{
    private string $root;
    private string $script;

    protected function setUp(): void
    {
        $this->script = dirname(__DIR__, 3) . '/scripts/set-release-version.php';
        $this->root = sys_get_temp_dir() . '/kintai-relver-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/config', 0777, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->root . '/config/app.php');
        @rmdir($this->root . '/config');
        @rmdir($this->root);
    }

    private function writeConfig(string $version = "'0.2.0'"): string
    {
        $contents = "<?php\n\ndeclare(strict_types=1);\n\nreturn [\n    'name' => env('APP_NAME', 'Kintai'),\n    'debug' => env('APP_DEBUG', false),\n    'version' => {$version},\n];\n";
        file_put_contents($this->root . '/config/app.php', $contents);

        return $contents;
    }

    /** @return array{0: int, 1: string} [code de sortie, sortie standard + erreur] */
    private function runScript(string $version, ?string $root = null): array
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->script) . ' ' . escapeshellarg($version) . ' ' . escapeshellarg($root ?? $this->root) . ' 2>&1';
        exec($cmd, $lines, $code);

        return [$code, implode("\n", $lines)];
    }

    public function testItWritesTheVersionInConfig(): void
    {
        $this->writeConfig();

        [$code] = $this->runScript('0.3.1');

        $this->assertSame(0, $code);
        $this->assertStringContainsString("'version' => '0.3.1',", (string) file_get_contents($this->root . '/config/app.php'));
    }

    public function testEverythingElseInTheConfigIsLeftUntouched(): void
    {
        $before = $this->writeConfig();

        $this->runScript('1.0.0');

        $after = (string) file_get_contents($this->root . '/config/app.php');
        $this->assertSame(str_replace("'version' => '0.2.0'", "'version' => '1.0.0'", $before), $after);
    }

    public function testConfigStillParsesAsPhpAfterwards(): void
    {
        $this->writeConfig();
        $this->runScript('0.3.0');

        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($this->root . '/config/app.php') . ' 2>&1', $out, $code);

        $this->assertSame(0, $code, implode("\n", $out));
    }

    public function testRunningTwiceWithTheSameVersionIsHarmless(): void
    {
        $this->writeConfig();
        $this->runScript('0.3.0');
        $first = (string) file_get_contents($this->root . '/config/app.php');

        [$code] = $this->runScript('0.3.0');

        $this->assertSame(0, $code);
        $this->assertSame($first, (string) file_get_contents($this->root . '/config/app.php'));
    }

    /** @return array<string, array{string}> */
    public static function invalidVersions(): array
    {
        return [
            'préfixe v'      => ['v0.3.0'],
            'deux chiffres'  => ['0.3'],
            'suffixe'        => ['0.3.0-alpha'],
            'vide'           => [''],
            'injection PHP'  => ["0.3.0'; system('x'); '"],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidVersions')]
    public function testAnInvalidVersionIsRefusedAndNothingIsWritten(string $version): void
    {
        $before = $this->writeConfig();

        [$code, $output] = $this->runScript($version);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Version invalide', $output);
        $this->assertSame($before, (string) file_get_contents($this->root . '/config/app.php'));
    }

    public function testAConfigWithoutALiteralVersionFailsLoudly(): void
    {
        // env(...) : la substitution ne matcherait rien et l'archive publierait une version fausse sans le dire.
        $before = $this->writeConfig("env('APP_VERSION', '0.1.1')");

        [$code, $output] = $this->runScript('0.3.0');

        $this->assertSame(1, $code);
        $this->assertStringContainsString('littéral introuvable', $output);
        $this->assertSame($before, (string) file_get_contents($this->root . '/config/app.php'));
    }

    public function testAMissingConfigFileFailsLoudly(): void
    {
        [$code, $output] = $this->runScript('0.3.0', $this->root . '/absent');

        $this->assertSame(1, $code);
        $this->assertStringContainsString('introuvable', $output);
    }

    public function testTheRealConfigHasALiteralVersionTheScriptCanRewrite(): void
    {
        // Garde-fou : si quelqu'un remet env('APP_VERSION', …) dans config/app.php du dépôt, la release échouerait.
        $config = (string) file_get_contents(dirname(__DIR__, 3) . '/config/app.php');

        $this->assertMatchesRegularExpression("/'version'\s*=>\s*'\d+\.\d+\.\d+'/", $config);
    }

    public function testTheReleaseWorkflowWritesTheVersionBeforeTaggingAndNeverPushesTheBranch(): void
    {
        // Le dépôt (et son .github/) est la racine de l'application, ou son parent pendant la migration vers Laravel.
        $root = dirname(__DIR__, 3);
        $file = is_file($root . '/.github/workflows/release.yml') ? $root : dirname($root);
        $workflow = (string) file_get_contents($file . '/.github/workflows/release.yml');

        $write = strpos($workflow, 'php scripts/set-release-version.php "$VERSION"');
        $tag = strpos($workflow, 'git tag "$TAG"');

        $this->assertNotFalse($write, 'release.yml doit appeler scripts/set-release-version.php.');
        $this->assertNotFalse($tag);
        $this->assertLessThan($tag, $write, 'La version doit être écrite AVANT de poser le tag.');
        $this->assertStringContainsString('git push origin "$TAG"', $workflow);
        // Le commit de release n'est référencé que par le tag : la branche est protégée, on ne la pousse jamais.
        $this->assertDoesNotMatchRegularExpression('/git push(?! origin "\$TAG")/', $workflow);
    }
}
