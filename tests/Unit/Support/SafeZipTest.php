<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\SafeZip;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

/** Audit SEC-09 : une archive ne peut rien écrire hors du dossier d'extraction. */
final class SafeZipTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/kintai-zip-' . uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $path) {
            $path->isDir() ? @rmdir($path->getPathname()) : @unlink($path->getPathname());
        }
        @rmdir($this->dir);
    }

    /** @param array<string, string> $entries nom => contenu */
    private function archive(array $entries, ?callable $after = null): ZipArchive
    {
        $path = $this->dir . '/test.zip';
        $zip  = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        if ($after !== null) {
            $after($zip);
        }
        $zip->close();
        $zip->open($path);

        return $zip;
    }

    public function testAnOrdinaryArchiveIsExtracted(): void
    {
        $zip = $this->archive(['repo-abc/bundle.json' => '{}', 'repo-abc/src/A.php' => '<?php']);

        SafeZip::extract($zip, $this->dir . '/out');

        $this->assertFileExists($this->dir . '/out/repo-abc/src/A.php');
    }

    /** @return array<string, array{string}> */
    public static function dangerousNames(): array
    {
        return [
            'remontée'        => ['../evil.php'],
            'remontée cachée' => ['repo/../../evil.php'],
            'chemin absolu'   => ['/etc/cron.d/evil'],
            'lecteur'         => ['C:/Windows/evil.php'],
            'antislash'       => ['repo\\..\\evil.php'],
        ];
    }

    #[DataProvider('dangerousNames')]
    public function testAnEntryOutsideTheDestinationIsRefusedAndNothingIsWritten(string $name): void
    {
        $zip = $this->archive(['repo/ok.txt' => 'ok', $name => 'x']);

        try {
            SafeZip::extract($zip, $this->dir . '/out');
            $this->fail('RuntimeException attendue');
        } catch (RuntimeException) {
            $this->assertFileDoesNotExist($this->dir . '/out/repo/ok.txt', 'rien n\'est écrit tant que tout n\'est pas accepté');
            $this->assertFileDoesNotExist($this->dir . '/evil.php');
        }
    }

    public function testASymbolicLinkEntryIsRefused(): void
    {
        $zip = $this->archive(['repo/link' => '/etc/passwd'], function (ZipArchive $z): void {
            $z->setExternalAttributesName('repo/link', ZipArchive::OPSYS_UNIX, 0120777 << 16);
        });

        $this->expectException(RuntimeException::class);
        SafeZip::extract($zip, $this->dir . '/out');
    }

    public function testADecompressedSizeAboveTheLimitIsRefused(): void
    {
        $zip = $this->archive(['repo/big.txt' => str_repeat('a', 2048)]);

        $this->expectException(RuntimeException::class);
        SafeZip::extract($zip, $this->dir . '/out', maxBytes: 1024);
    }

    public function testASuspiciousCompressionRatioIsRefused(): void
    {
        $zip = $this->archive(['repo/bomb.txt' => str_repeat("\0", 5 * 1024 * 1024)]);

        $this->expectException(RuntimeException::class);
        SafeZip::extract($zip, $this->dir . '/out');
    }
}
