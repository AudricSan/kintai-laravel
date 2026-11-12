<?php

namespace Tests\Unit\Update;

use App\Update\ReleaseArchive;
use App\Update\Version;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

require_once dirname(__DIR__, 3).'/tools/release/ReleaseBuilder.php';

class ReleaseBuilderTest extends TestCase
{
    private string $source;

    private string $output;

    protected function setUp(): void
    {
        parent::setUp();

        $base = sys_get_temp_dir().'/kintai-release-'.bin2hex(random_bytes(6));
        $this->source = $base.'/src';
        $this->output = $base.'/out';

        $files = [
            'artisan' => '<?php',
            'composer.json' => '{"require":{"php":"^8.3"}}',
            'app/Foo.php' => '<?php',
            'vendor/autoload.php' => '<?php',
            'vendor/pkg/a.php' => '<?php',
            'public/build/app.js' => 'x',
            'public/hot' => 'x',
            '.env' => 'APP_KEY=secret',
            '.env.example' => 'APP_KEY=',
            'storage/logs/laravel.log' => 'log',
            'storage/.gitkeep' => '',
            'bootstrap/cache/packages.php' => '<?php',
            'bootstrap/cache/.gitignore' => '*',
            'database/database.sqlite' => 'db',
            'database/migrations/a.php' => '<?php',
            'node_modules/x/index.js' => 'x',
            'tests/FooTest.php' => '<?php',
            '.git/HEAD' => 'ref',
            '.github/workflows/tests.yml' => 'x',
            'docs/PLAN.md' => 'x',
        ];

        foreach ($files as $path => $contents) {
            File::ensureDirectoryExists(dirname($this->source.'/'.$path));
            file_put_contents($this->source.'/'.$path, $contents);
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(dirname($this->source));

        parent::tearDown();
    }

    public function test_builds_an_archive_accepted_by_the_update_pipeline(): void
    {
        $result = (new \ReleaseBuilder($this->source))->build('1.2.3', $this->output, '1.0.0');

        $this->assertSame($this->output.DIRECTORY_SEPARATOR.'kintai-1.2.3.zip', $result['zip']);

        $info = ReleaseArchive::inspect($result['zip'], Version::parse('1.2.3'));

        $this->assertSame('8.3', $info['min_php']);
        $this->assertSame('1.0.0', $info['min_from_version']);

        $zip = new ZipArchive;
        $zip->open($result['zip']);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();

        foreach (['artisan', 'app/Foo.php', 'vendor/pkg/a.php', 'public/build/app.js', '.env.example', 'database/migrations/a.php', 'kintai-release.json'] as $expected) {
            $this->assertContains($expected, $names);
        }

        foreach (['.env', 'public/hot', 'storage/logs/laravel.log', 'storage/.gitkeep', 'bootstrap/cache/packages.php', 'database/database.sqlite', 'node_modules/x/index.js', 'tests/FooTest.php', '.git/HEAD', 'docs/PLAN.md'] as $forbidden) {
            $this->assertNotContains($forbidden, $names);
        }
    }

    public function test_checksum_file_matches_the_archive(): void
    {
        $result = (new \ReleaseBuilder($this->source))->build('1.2.3-beta.1', $this->output);

        $line = trim((string) file_get_contents($result['checksum']));

        $this->assertSame(hash_file('sha256', $result['zip']).'  kintai-1.2.3-beta.1.zip', $line);
    }

    public function test_rejects_an_incomplete_tree_and_an_invalid_version(): void
    {
        File::delete($this->source.'/vendor/autoload.php');

        try {
            (new \ReleaseBuilder($this->source))->build('1.2.3', $this->output);
            $this->fail('Une arborescence sans vendor/autoload.php doit être refusée.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('vendor', $e->getMessage());
        }

        $this->expectException(RuntimeException::class);
        (new \ReleaseBuilder($this->source))->build('abc', $this->output);
    }
}
