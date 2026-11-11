<?php

namespace Tests\Feature\Update;

use App\Backups\BackupManager;
use App\Update\ReleaseArchive;
use App\Update\UpdateException;
use App\Update\Version;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use ZipArchive;

class ReleaseArchiveTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'kintai-zip-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->beforeApplicationDestroyed(fn () => BackupManager::removeDirectory($this->dir));
    }

    /** @return array<string, string> */
    private function base(string $version = '1.2.3'): array
    {
        return [
            'kintai-release.json' => (string) json_encode(['version' => $version, 'min_php' => '8.3']),
            'artisan' => '#!/usr/bin/env php',
            'vendor/autoload.php' => '<?php',
            'composer.json' => '{}',
            'app/Foo.php' => '<?php // foo',
        ];
    }

    /**
     * @param  array<string, string>  $entries
     * @param  callable(ZipArchive): void|null  $tweak
     */
    private function zip(array $entries, string $prefix = '', ?callable $tweak = null): string
    {
        $path = $this->dir.DIRECTORY_SEPARATOR.bin2hex(random_bytes(3)).'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);

        foreach ($entries as $name => $contents) {
            $zip->addFromString($prefix.$name, $contents);
        }

        if ($tweak !== null) {
            $tweak($zip);
        }

        $zip->close();

        return $path;
    }

    public function test_valid_archive_without_root_folder(): void
    {
        $info = ReleaseArchive::inspect($this->zip($this->base()));

        $this->assertSame('1.2.3', (string) $info['version']);
        $this->assertSame('8.3', $info['min_php']);
        $this->assertSame(5, $info['files']);
        $this->assertSame(strlen('#!/usr/bin/env php') + strlen('<?php') + 2 + strlen('<?php // foo') + strlen((string) json_encode(['version' => '1.2.3', 'min_php' => '8.3'])), $info['bytes']);
    }

    public function test_inspect_reports_the_minimum_version_to_update_from(): void
    {
        $this->assertNull(ReleaseArchive::inspect($this->zip($this->base()))['min_from_version']);

        $entries = ['kintai-release.json' => (string) json_encode(['version' => '1.2.3', 'min_from_version' => '1.1.0'])] + $this->base();

        $this->assertSame('1.1.0', ReleaseArchive::inspect($this->zip($entries))['min_from_version']);
    }

    public function test_valid_archive_with_root_folder_is_extracted_without_it(): void
    {
        $path = $this->zip($this->base(), 'kintai-1.2.3/', fn (ZipArchive $zip) => $zip->addEmptyDir('kintai-1.2.3/storage/logs'));
        $target = $this->dir.DIRECTORY_SEPARATOR.'out';

        $this->assertSame('1.2.3', (string) ReleaseArchive::inspect($path, Version::parse('v1.2.3'))['version']);

        ReleaseArchive::extractTo($path, $target);

        foreach ($this->base() as $name => $contents) {
            $this->assertSame($contents, file_get_contents($target.'/'.$name));
        }

        $this->assertDirectoryExists($target.'/storage/logs');
        $this->assertDirectoryDoesNotExist($target.'/kintai-1.2.3');
    }

    public function test_extraction_reproduces_the_tree_without_root(): void
    {
        $path = $this->zip($this->base() + ['resources/views/a/b.blade.php' => 'x', 'storage/.gitkeep' => '']);
        $target = $this->dir.DIRECTORY_SEPARATOR.'out';

        ReleaseArchive::extractTo($path, $target);

        $this->assertSame('x', file_get_contents($target.'/resources/views/a/b.blade.php'));
        $this->assertFileExists($target.'/storage/.gitkeep');
    }

    /** @return array<string, array{array<string, string>}> */
    public static function unsafe(): array
    {
        return [
            'parent traversal' => [['../evil' => 'x']],
            'nested traversal' => [['app/../../evil' => 'x']],
            'absolute path' => [['/etc/evil' => 'x']],
            'backslash' => [['app\\evil.php' => 'x']],
            'drive letter' => [['C:evil.php' => 'x']],
            'dot env' => [['.env' => 'SECRET=1']],
            'dot env uppercase' => [['.ENV' => 'SECRET=1']],
            'git' => [['.git/config' => 'x']],
            'storage file' => [['storage/x' => 'x']],
            'storage logs' => [['storage/logs/laravel.log' => 'x']],
            'sqlite' => [['database/database.sqlite' => 'x']],
            'bootstrap cache' => [['bootstrap/cache/config.php' => '<?php']],
        ];
    }

    /** @param  array<string, string>  $extra */
    #[DataProvider('unsafe')]
    public function test_unsafe_or_forbidden_entries_are_refused(array $extra): void
    {
        $path = $this->zip($this->base() + $extra);
        $target = $this->dir.DIRECTORY_SEPARATOR.'out';

        try {
            ReleaseArchive::inspect($path);
            $this->fail('UpdateException expected');
        } catch (UpdateException) {
            $this->addToAssertionCount(1);
        }

        try {
            ReleaseArchive::extractTo($path, $target);
            $this->fail('UpdateException expected');
        } catch (UpdateException) {
            $this->assertFileDoesNotExist($this->dir.DIRECTORY_SEPARATOR.'evil');
            $this->assertFileDoesNotExist($target.'/artisan');
        }
    }

    public function test_forbidden_paths_are_also_refused_under_a_root_folder(): void
    {
        $this->expectException(UpdateException::class);

        ReleaseArchive::inspect($this->zip($this->base() + ['.env' => 'x'], 'kintai-1.2.3/'));
    }

    public function test_symlink_is_refused(): void
    {
        $path = $this->zip($this->base(), '', function (ZipArchive $zip) {
            $zip->addFromString('app/link', '/etc/passwd');
            $zip->setExternalAttributesName('app/link', ZipArchive::OPSYS_UNIX, 0120777 << 16);
        });

        $this->expectException(UpdateException::class);

        ReleaseArchive::inspect($path);
    }

    public function test_duplicate_entries_are_refused(): void
    {
        // ZipArchive remplace une entrée existante : on renomme octet pour octet la seconde pour forger un vrai doublon.
        $path = $this->zip($this->base() + ['app/one.php' => 'a', 'app/two.php' => 'b']);
        file_put_contents($path, str_replace('app/two.php', 'app/one.php', (string) file_get_contents($path)));

        $this->expectException(UpdateException::class);

        ReleaseArchive::inspect($path);
    }

    /** @return array<string, array{string}> */
    public static function missing(): array
    {
        return [['kintai-release.json'], ['artisan'], ['vendor/autoload.php'], ['composer.json']];
    }

    #[DataProvider('missing')]
    public function test_required_entries_must_exist(string $missing): void
    {
        $entries = $this->base();
        unset($entries[$missing]);

        $this->expectException(UpdateException::class);

        ReleaseArchive::inspect($this->zip($entries));
    }

    public function test_invalid_manifest_is_refused(): void
    {
        $this->expectException(UpdateException::class);

        ReleaseArchive::inspect($this->zip(['kintai-release.json' => '{"version":"nope"}'] + $this->base()));
    }

    public function test_manifest_version_must_match_the_expected_one(): void
    {
        $path = $this->zip($this->base('1.2.3'));

        $this->assertSame('1.2.3', (string) ReleaseArchive::inspect($path, Version::parse('1.2.3'))['version']);

        $this->expectException(UpdateException::class);

        ReleaseArchive::inspect($path, Version::parse('1.2.4'));
    }

    public function test_entry_count_limit(): void
    {
        config(['kintai.update.max_entries' => 3]);

        $this->expectException(UpdateException::class);

        ReleaseArchive::inspect($this->zip($this->base()));
    }

    public function test_uncompressed_size_limit(): void
    {
        config(['kintai.update.max_uncompressed_bytes' => 50]);

        $this->expectException(UpdateException::class);

        ReleaseArchive::inspect($this->zip($this->base() + ['big.bin' => str_repeat('a', 100)]));
    }

    public function test_unreadable_archive_is_refused(): void
    {
        $path = $this->dir.DIRECTORY_SEPARATOR.'bad.zip';
        file_put_contents($path, 'not a zip');

        $this->expectException(UpdateException::class);

        ReleaseArchive::inspect($path);
    }
}
