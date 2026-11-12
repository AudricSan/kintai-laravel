<?php

namespace Tests\Feature\Update;

use App\Update\ReleaseClient;
use App\Update\UpdateChannel;
use App\Update\UpdateException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReleaseClientTest extends TestCase
{
    private const API = 'https://api.github.com/repos/Acme/Kintai/releases*';

    protected function setUp(): void
    {
        parent::setUp();

        config(['kintai.update.repository' => 'Acme/Kintai', 'kintai.support.github_token' => null]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function release(string $tag, array $overrides = []): array
    {
        $name = 'kintai-'.ltrim($tag, 'v');
        $base = 'https://github.com/Acme/Kintai/releases/download/'.$tag.'/';

        return array_merge([
            'tag_name' => $tag,
            'draft' => false,
            'published_at' => '2026-01-01T10:00:00Z',
            'body' => 'Notes '.$tag,
            'assets' => [
                ['name' => $name.'.zip', 'size' => 1234, 'browser_download_url' => $base.$name.'.zip'],
                ['name' => $name.'.zip.sha256', 'size' => 80, 'browser_download_url' => $base.$name.'.zip.sha256'],
            ],
        ], $overrides);
    }

    /** @param  list<array<string, mixed>>  $releases */
    private function fake(array $releases): void
    {
        Http::fake([self::API => Http::response($releases)]);
    }

    public function test_picks_highest_semver_not_most_recent_date(): void
    {
        $this->fake([
            $this->release('v1.0.0', ['published_at' => '2026-06-01T00:00:00Z']),
            $this->release('v1.2.0', ['published_at' => '2026-01-01T00:00:00Z']),
            $this->release('v1.1.0', ['published_at' => '2026-03-01T00:00:00Z']),
        ]);

        $release = (new ReleaseClient)->latest(UpdateChannel::Release);

        $this->assertSame('1.2.0', (string) $release->version);
        $this->assertSame('v1.2.0', $release->tag);
        $this->assertSame('Notes v1.2.0', $release->notes);
        $this->assertSame(1234, $release->zipSize);
        $this->assertSame('https://github.com/Acme/Kintai/releases/download/v1.2.0/kintai-1.2.0.zip', $release->zipUrl);
        $this->assertSame('https://github.com/Acme/Kintai/releases/download/v1.2.0/kintai-1.2.0.zip.sha256', $release->checksumUrl);
        $this->assertSame('2026-01-01', $release->publishedAt->toDateString());
    }

    public function test_drafts_are_ignored(): void
    {
        $this->fake([$this->release('v2.0.0', ['draft' => true]), $this->release('v1.0.0')]);

        $this->assertSame('1.0.0', (string) (new ReleaseClient)->latest(UpdateChannel::Release)->version);
    }

    public function test_channel_is_respected(): void
    {
        $this->fake([$this->release('v1.0.0'), $this->release('v1.1.0-rc.1'), $this->release('v1.2.0-alpha.1')]);

        $this->assertSame('1.0.0', (string) (new ReleaseClient)->latest(UpdateChannel::Release)->version);
        $this->assertSame('1.1.0-rc.1', (string) (new ReleaseClient)->latest(UpdateChannel::Beta)->version);
        $this->assertSame('1.2.0-alpha.1', (string) (new ReleaseClient)->latest(UpdateChannel::Alpha)->version);
    }

    public function test_returns_null_without_candidate(): void
    {
        $this->fake([$this->release('v1.0.0-beta.1')]);

        $this->assertNull((new ReleaseClient)->latest(UpdateChannel::Release));
    }

    public function test_release_without_checksum_or_zip_is_ignored(): void
    {
        $noSum = $this->release('v2.0.0');
        array_pop($noSum['assets']);
        $noZip = $this->release('v3.0.0');
        array_shift($noZip['assets']);
        $this->fake([$noSum, $noZip, $this->release('v1.0.0')]);

        $this->assertSame('1.0.0', (string) (new ReleaseClient)->latest(UpdateChannel::Release)->version);
    }

    public function test_release_with_asset_outside_the_allow_list_is_ignored(): void
    {
        $evil = $this->release('v2.0.0');
        $evil['assets'][0]['browser_download_url'] = 'https://evil.example/kintai-2.0.0.zip';
        $otherRepo = $this->release('v3.0.0');
        $otherRepo['assets'][1]['browser_download_url'] = 'https://github.com/Other/Repo/releases/download/v3.0.0/kintai-3.0.0.zip.sha256';
        $this->fake([$evil, $otherRepo, $this->release('v1.0.0')]);

        $this->assertSame('1.0.0', (string) (new ReleaseClient)->latest(UpdateChannel::Release)->version);
    }

    public function test_api_asset_urls_are_accepted(): void
    {
        $release = $this->release('v1.0.0');
        $release['assets'][0]['browser_download_url'] = 'https://api.github.com/repos/Acme/Kintai/releases/assets/11';
        $release['assets'][1]['browser_download_url'] = 'https://api.github.com/repos/Acme/Kintai/releases/assets/12';
        $this->fake([$release]);

        $this->assertSame('https://api.github.com/repos/Acme/Kintai/releases/assets/11', (new ReleaseClient)->latest(UpdateChannel::Release)->zipUrl);
    }

    public function test_notes_are_truncated(): void
    {
        $this->fake([$this->release('v1.0.0', ['body' => str_repeat('é', 15000)])]);

        $this->assertSame(10000, mb_strlen((new ReleaseClient)->latest(UpdateChannel::Release)->notes));
    }

    public function test_invalid_repository_throws_without_request(): void
    {
        Http::fake();
        config(['kintai.update.repository' => 'not a repository']);

        try {
            (new ReleaseClient)->latest(UpdateChannel::Release);
            $this->fail('UpdateException expected');
        } catch (UpdateException) {
            Http::assertNothingSent();
            $this->addToAssertionCount(1);
        }
    }

    public function test_http_error_throws(): void
    {
        Http::fake([self::API => Http::response('boom', 500)]);

        $this->expectException(UpdateException::class);

        (new ReleaseClient)->latest(UpdateChannel::Release);
    }

    public function test_invalid_json_throws(): void
    {
        Http::fake([self::API => Http::response('<html>not json</html>', 200)]);

        $this->expectException(UpdateException::class);

        (new ReleaseClient)->latest(UpdateChannel::Release);
    }

    public function test_network_error_throws(): void
    {
        Http::fake(fn () => throw new ConnectionException('down'));

        $this->expectException(UpdateException::class);

        (new ReleaseClient)->latest(UpdateChannel::Release);
    }

    public function test_token_and_headers_are_sent_to_the_api(): void
    {
        config(['kintai.support.github_token' => 'ghp_secret']);
        $this->fake([]);

        (new ReleaseClient)->latest(UpdateChannel::Release);

        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer ghp_secret')
            && $request->hasHeader('Accept', 'application/vnd.github+json')
            && $request->hasHeader('User-Agent')
            && str_starts_with($request->url(), 'https://api.github.com/repos/Acme/Kintai/releases'));
    }

    public function test_no_authorization_header_without_token(): void
    {
        $this->fake([]);

        (new ReleaseClient)->latest(UpdateChannel::Release);

        Http::assertSent(fn (Request $request) => ! $request->hasHeader('Authorization'));
    }
}
