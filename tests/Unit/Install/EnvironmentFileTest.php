<?php

namespace Tests\Unit\Install;

use App\Install\EnvironmentFile;
use App\Install\InstallFailure;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class EnvironmentFileTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/kintai-env-'.bin2hex(random_bytes(6));
        File::makeDirectory($this->directory);
        file_put_contents($this->directory.'/.env.example', "APP_NAME=Kintai\nAPP_KEY=\n# DB_HOST=127.0.0.1\nDB_CONNECTION=sqlite\n");
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    private function file(): EnvironmentFile
    {
        return new EnvironmentFile($this->directory.'/.env', $this->directory.'/.env.example');
    }

    public function test_creates_from_example_and_never_overwrites(): void
    {
        $file = $this->file();

        $this->assertTrue($file->createFromExample());
        file_put_contents($this->directory.'/.env', "APP_NAME=Mine\n");

        $this->assertFalse($file->createFromExample());
        $this->assertSame('Mine', $file->get('APP_NAME'));
    }

    public function test_missing_example_fails(): void
    {
        unlink($this->directory.'/.env.example');

        $this->expectException(InstallFailure::class);

        $this->file()->createFromExample();
    }

    public function test_set_replaces_active_then_commented_then_appends(): void
    {
        $file = $this->file();
        $file->createFromExample();

        $file->set(['APP_KEY' => 'base64:abc+/=', 'DB_HOST' => 'db.example.test', 'DB_PORT' => '3307']);

        $contents = (string) file_get_contents($this->directory.'/.env');
        $this->assertStringContainsString("APP_KEY=base64:abc+/=\n", $contents);
        $this->assertStringContainsString("DB_HOST=db.example.test\n", $contents);
        $this->assertStringNotContainsString('# DB_HOST', $contents);
        $this->assertStringEndsWith("DB_PORT=3307\n", $contents);
        $this->assertSame('Kintai', $file->get('APP_NAME'));
    }

    public function test_special_values_are_quoted_and_read_back(): void
    {
        $file = $this->file();
        $file->createFromExample();

        $file->set(['DB_PASSWORD' => 'pa ss"w$rd#1']);

        $this->assertSame('pa ss"w$rd#1', $file->get('DB_PASSWORD'));
    }

    public function test_forget_removes_active_variables(): void
    {
        $file = $this->file();
        $file->createFromExample();

        $file->forget(['DB_CONNECTION']);

        $this->assertNull($file->get('DB_CONNECTION'));
        $this->assertSame('Kintai', $file->get('APP_NAME'));
    }
}
