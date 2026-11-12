<?php

namespace Tests\Feature\Backups;

use App\Backups\BackupException;
use App\Backups\BackupRestorer;
use App\Backups\DefaultBackupFileRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Kintai\Contracts\Backups\BackupFileRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\UsesBackupDirectory;
use Tests\TestCase;
use ZipArchive;

/**
 * Dossiers de fichiers téléversés déclarés par les bundles (`BackupFileRegistry`, contrat 1.4) : sauvegardés avec les
 * avatars, restaurés avec les mêmes contrôles stricts, et seuls les dossiers déclarés sont réécrits.
 */
class BackupFileAreasTest extends TestCase
{
    use RefreshDatabase;
    use UsesBackupDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpBackupDirectory();
        app(BackupFileRegistry::class)->register('gallery', 'gallery');
    }

    /**
     * @return list<string>
     */
    private function entries(string $id): array
    {
        $zip = new ZipArchive;
        $zip->open($this->backupDir.DIRECTORY_SEPARATOR.$id);
        $names = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = (string) $zip->getNameIndex($i);
        }

        $zip->close();
        sort($names);

        return $names;
    }

    public function test_a_declared_folder_is_included_in_the_backup_with_the_avatars(): void
    {
        Storage::disk('local')->put('avatars/user_1_abcdefabcdefabcd.png', 'AVATAR');
        Storage::disk('local')->put('gallery/'.str_repeat('a', 32).'.jpg', 'PHOTO');
        // Ni un nom hors motif, ni un autre type de fichier, ni un autre dossier ne part dans l'archive.
        Storage::disk('local')->put('gallery/notes.txt', 'text');
        Storage::disk('local')->put('gallery/.hidden.jpg', 'hidden');
        Storage::disk('local')->put('other/'.str_repeat('b', 32).'.jpg', 'other');

        $backup = $this->backups()->create();

        $this->assertSame(
            ['backup.json', 'database.sqlite', 'files/avatars/user_1_abcdefabcdefabcd.png', 'files/gallery/'.str_repeat('a', 32).'.jpg'],
            $this->entries($backup->id),
        );

        $zip = new ZipArchive;
        $zip->open($backup->path);
        $this->assertSame(2, json_decode((string) $zip->getFromName('backup.json'), true)['files']);
        $this->assertSame('PHOTO', $zip->getFromName('files/gallery/'.str_repeat('a', 32).'.jpg'));
        $zip->close();
    }

    public function test_restoring_puts_the_declared_folder_back_and_removes_files_the_database_no_longer_knows(): void
    {
        Storage::disk('local')->put('gallery/'.str_repeat('a', 32).'.jpg', 'ORIGINAL');
        $backup = $this->backups()->create();

        Storage::disk('local')->put('gallery/'.str_repeat('a', 32).'.jpg', 'CHANGED');
        Storage::disk('local')->put('gallery/'.str_repeat('c', 32).'.jpg', 'LATER');
        Storage::disk('local')->put('other/keep.txt', 'untouched folder');

        app(BackupRestorer::class)->restore($backup);

        $this->assertSame('ORIGINAL', Storage::disk('local')->get('gallery/'.str_repeat('a', 32).'.jpg'));
        Storage::disk('local')->assertMissing('gallery/'.str_repeat('c', 32).'.jpg');
        Storage::disk('local')->assertExists('other/keep.txt');
    }

    public function test_entries_of_an_unknown_folder_are_checked_but_never_restored(): void
    {
        $id = $this->forgeArchive(['files/unknown/'.str_repeat('a', 32).'.jpg' => 'X', 'files/gallery/'.str_repeat('b', 32).'.png' => 'OK']);

        app(BackupRestorer::class)->restore($this->backups()->find($id) ?? throw new \LogicException);

        Storage::disk('local')->assertExists('gallery/'.str_repeat('b', 32).'.png');
        Storage::disk('local')->assertMissing('unknown/'.str_repeat('a', 32).'.jpg');
    }

    /**
     * @return array<string, array{0: array<string, string>}>
     */
    public static function invalidEntries(): array
    {
        return [
            'zip-slip in a bundle folder' => [['files/gallery/../../evil.png' => 'x']],
            'script in a bundle folder' => [['files/gallery/shell.php' => '<?php']],
            'hidden file in a bundle folder' => [['files/gallery/.htaccess' => 'x']],
            'nested folder in a bundle folder' => [['files/gallery/sub/x.png' => 'x']],
            'folder key with a capital letter' => [['files/Gallery/x.png' => 'x']],
            'folder key with a dot' => [['files/gal.lery/x.png' => 'x']],
            'file directly in files' => [['files/x.png' => 'x']],
            'too deep' => [['files/gallery/a/b/x.png' => 'x']],
        ];
    }

    /**
     * @param  array<string, string>  $entries
     */
    #[DataProvider('invalidEntries')]
    public function test_an_invalid_entry_in_a_bundle_folder_refuses_the_archive(array $entries): void
    {
        $id = $this->forgeArchive($entries);

        $this->expectException(BackupException::class);
        app(BackupRestorer::class)->check($this->backups()->find($id) ?? throw new \LogicException);
    }

    public function test_the_registry_validates_keys_and_directories(): void
    {
        $registry = new DefaultBackupFileRegistry;
        $registry->register('store-photos', 'store-photos');
        $registry->register('store-photos', 'store-photos');

        $this->assertSame(['store-photos' => 'store-photos'], $registry->all());

        foreach ([['avatars', 'x'], ['Bad', 'x'], ['a/b', 'x'], ['..', 'x'], ['ok', '../x'], ['ok', 'a/b'], ['ok', '.hidden'], ['ok', 'backups'], ['ok', ''], ['store-photos', 'other'], ['another', 'store-photos']] as [$key, $directory]) {
            try {
                $registry->register($key, $directory);
                $this->fail("[{$key}] => [{$directory}] must be refused.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(['store-photos' => 'store-photos'], $registry->all());
    }
}
