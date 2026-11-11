<?php

namespace Tests\Feature\Update;

use App\Update\Release;
use App\Update\ReleaseDownloader;
use App\Update\UpdateException;
use App\Update\Version;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReleaseDownloaderTest extends TestCase
{
    private const ZIP = 'https://github.com/Acme/Kintai/releases/download/v1.0.0/kintai-1.0.0.zip';

    private const SUM = 'https://github.com/Acme/Kintai/releases/download/v1.0.0/kintai-1.0.0.zip.sha256';

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'kintai-dl-'.bin2hex(random_bytes(4));
        config(['kintai.support.github_token' => 'ghp_secret', 'kintai.update.max_download_bytes' => 1024 * 1024]);

        $this->beforeApplicationDestroyed(function () {
            foreach (glob($this->dir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->dir);
        });
    }

    private function release(int $size = 10): Release
    {
        return new Release(Version::parse('1.0.0'), 'v1.0.0', '', self::ZIP, $size, self::SUM, null);
    }

    public function test_downloads_and_verifies_checksum(): void
    {
        $content = random_bytes(500);
        Http::fake([self::ZIP => Http::response($content), self::SUM => Http::response(hash('sha256', $content)."\n")]);

        $path = (new ReleaseDownloader)->download($this->release(500), $this->dir);

        $this->assertFileExists($path);
        $this->assertSame($content, file_get_contents($path));
    }

    public function test_checksum_file_with_file_name_is_accepted(): void
    {
        $content = 'zip-content';
        Http::fake([self::ZIP => Http::response($content), self::SUM => Http::response(strtoupper(hash('sha256', $content)).'  kintai-1.0.0.zip'."\n")]);

        $path = (new ReleaseDownloader)->download($this->release(), $this->dir);

        $this->assertFileExists($path);
    }

    public function test_wrong_checksum_deletes_the_file(): void
    {
        Http::fake([self::ZIP => Http::response('zip-content'), self::SUM => Http::response(str_repeat('a', 64))]);

        try {
            (new ReleaseDownloader)->download($this->release(), $this->dir);
            $this->fail('UpdateException expected');
        } catch (UpdateException) {
            $this->assertSame([], glob($this->dir.DIRECTORY_SEPARATOR.'*'));
        }
    }

    public function test_invalid_checksum_format_is_refused(): void
    {
        Http::fake([self::ZIP => Http::response('zip-content'), self::SUM => Http::response('not a checksum')]);

        $this->expectException(UpdateException::class);

        (new ReleaseDownloader)->download($this->release(), $this->dir);
    }

    public function test_received_size_above_the_limit_is_refused_even_if_announced_small(): void
    {
        config(['kintai.update.max_download_bytes' => 100]);
        $content = str_repeat('x', 500);
        Http::fake([self::ZIP => Http::response($content), self::SUM => Http::response(hash('sha256', $content))]);

        try {
            (new ReleaseDownloader)->download($this->release(10), $this->dir);
            $this->fail('UpdateException expected');
        } catch (UpdateException) {
            $this->assertSame([], glob($this->dir.DIRECTORY_SEPARATOR.'*'));
        }
    }

    public function test_announced_size_above_the_limit_is_refused_without_download(): void
    {
        config(['kintai.update.max_download_bytes' => 100]);
        Http::fake();

        try {
            (new ReleaseDownloader)->download($this->release(5000), $this->dir);
            $this->fail('UpdateException expected');
        } catch (UpdateException) {
            Http::assertNothingSent();
            $this->addToAssertionCount(1);
        }
    }

    public function test_http_error_throws(): void
    {
        Http::fake([self::ZIP => Http::response('', 404), self::SUM => Http::response(str_repeat('a', 64))]);

        $this->expectException(UpdateException::class);

        (new ReleaseDownloader)->download($this->release(), $this->dir);
    }

    public function test_token_is_never_sent_and_octet_stream_is_requested(): void
    {
        $content = 'zip-content';
        Http::fake([self::ZIP => Http::response($content), self::SUM => Http::response(hash('sha256', $content))]);

        (new ReleaseDownloader)->download($this->release(), $this->dir);

        Http::assertSent(fn (Request $request) => ! $request->hasHeader('Authorization') && $request->hasHeader('Accept', 'application/octet-stream'));
        Http::assertSentCount(2);
    }
}
