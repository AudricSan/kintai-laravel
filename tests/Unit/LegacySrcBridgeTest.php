<?php

declare(strict_types=1);

namespace kintai\Tests\Unit;

use kintai\Contract\Contract;
use PHPUnit\Framework\TestCase;

/**
 * Passage d'une instance 0.x (code sous src/) à Laravel (code sous app/) par la mise à jour intégrée : l'archive de
 * release garde un relais src/ → app/ par classe, et le dépôt garde l'ancien chemin du contrat de bundle.
 */
final class LegacySrcBridgeTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/kintai-bridge-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/app/Core/Deep', 0755, true);
        mkdir($this->root . '/app/UI/View', 0755, true);
        mkdir($this->root . '/src/Core', 0755, true);
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    public function testTheOldContractPathStillAnnouncesTheContractVersion(): void
    {
        // Même lecture que GithubUpdateService::contractVersionAt() des instances 0.x.
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Contract/Contract.php');
        $this->assertSame(1, preg_match("/const VERSION = '(\\d+\\.\\d+\\.\\d+)'/", $source, $m));
        $this->assertSame(Contract::VERSION, $m[1]);
    }

    public function testEachClassGetsARelayThatLoadsTheAppFile(): void
    {
        $class = 'BridgeProbe' . bin2hex(random_bytes(4));
        file_put_contents($this->root . "/app/Core/Deep/{$class}.php", "<?php\nfinal class {$class} {}\n");
        file_put_contents($this->root . '/app/Core/helpers.php', "<?php\nfunction kintai_bridge_probe(): void {}\n");
        file_put_contents($this->root . '/app/UI/View/page.php', "<?php\nclass NotAView {}\n");
        file_put_contents($this->root . '/app/Core/Kept.php', "<?php\nclass Kept {}\n");
        file_put_contents($this->root . '/src/Core/Kept.php', 'committed');

        exec(PHP_BINARY . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/scripts/build-legacy-src-bridge.php') . ' ' . escapeshellarg($this->root), $out, $code);

        $this->assertSame(0, $code);
        $this->assertFileDoesNotExist($this->root . '/src/Core/helpers.php');
        $this->assertFileDoesNotExist($this->root . '/src/UI/View/page.php');
        $this->assertStringEqualsFile($this->root . '/src/Core/Kept.php', 'committed');

        require $this->root . "/src/Core/Deep/{$class}.php";
        $this->assertTrue(class_exists($class, false));
        // Inclure le relais une seconde fois ne redéfinit pas la classe.
        require $this->root . "/src/Core/Deep/{$class}.php";
    }
}
