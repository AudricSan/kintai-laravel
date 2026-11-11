<?php

namespace App\Update;

use App\Support\GithubRepository;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Lecture des releases GitHub du dépôt configuré (`kintai.update.repository`). Une release n'est retenue que si elle n'est pas
 * un brouillon, que son canal est accepté et qu'elle porte l'archive `kintai-X.Y.Z.zip` et sa somme `kintai-X.Y.Z.zip.sha256`,
 * hébergées sur GitHub (liste blanche d'adresses). La plus récente est choisie par numéro de version, jamais par date.
 */
class ReleaseClient
{
    public const NOTES_MAX = 10000;

    /**
     * @throws UpdateException
     */
    public function latest(UpdateChannel $channel): ?Release
    {
        $best = null;

        foreach ($this->releases() as $release) {
            if ($channel->accepts($release->version) && ($best === null || $release->version->isNewerThan($best->version))) {
                $best = $release;
            }
        }

        return $best;
    }

    /**
     * @return list<Release>
     *
     * @throws UpdateException
     */
    public function releases(): array
    {
        $repository = self::repository();

        try {
            $response = $this->request()->get('https://api.github.com/repos/'.$repository.'/releases', ['per_page' => 100]);
        } catch (ConnectionException) {
            throw new UpdateException(__('Could not reach GitHub to check for updates.'));
        }

        if ($response->status() !== 200) {
            throw new UpdateException(__('GitHub answered with an error (:status) while checking for updates.', ['status' => $response->status()]));
        }

        $data = json_decode($response->body(), true);

        if (! is_array($data)) {
            throw new UpdateException(__('The answer of GitHub could not be read.'));
        }

        $releases = [];

        foreach ($data as $item) {
            $release = is_array($item) ? $this->toRelease($item, $repository) : null;

            if ($release !== null) {
                $releases[] = $release;
            }
        }

        return $releases;
    }

    /**
     * @throws UpdateException
     */
    public static function repository(): string
    {
        $repository = trim((string) config('kintai.update.repository'));

        if (! GithubRepository::isValid($repository)) {
            throw new UpdateException(__('The update repository is not configured correctly.'));
        }

        return $repository;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function toRelease(array $item, string $repository): ?Release
    {
        if (($item['draft'] ?? false) !== false || ! is_string($item['tag_name'] ?? null) || ! is_array($item['assets'] ?? null)) {
            return null;
        }

        $version = Version::tryParse($item['tag_name']);

        if ($version === null) {
            return null;
        }

        // Le nom des assets suit le tag sans le « v » initial.
        $name = 'kintai-'.ltrim($item['tag_name'], 'v').'.zip';
        $zip = $checksum = null;

        foreach ($item['assets'] as $asset) {
            if (! is_array($asset) || ! is_string($asset['name'] ?? null)) {
                continue;
            }

            if ($asset['name'] === $name) {
                $zip = $asset;
            } elseif ($asset['name'] === $name.'.sha256') {
                $checksum = $asset;
            }
        }

        $zipUrl = $zip === null ? null : self::assetUrl($zip, $repository);
        $checksumUrl = $checksum === null ? null : self::assetUrl($checksum, $repository);

        if ($zip === null || $zipUrl === null || $checksumUrl === null) {
            return null;
        }

        $published = null;

        try {
            $published = is_string($item['published_at'] ?? null) ? CarbonImmutable::parse($item['published_at']) : null;
        } catch (Throwable) {
            // Date illisible : la release reste utilisable.
        }

        return new Release(
            $version,
            $item['tag_name'],
            mb_substr(is_string($item['body'] ?? null) ? $item['body'] : '', 0, self::NOTES_MAX),
            $zipUrl,
            max(0, (int) ($zip['size'] ?? 0)),
            $checksumUrl,
            $published,
        );
    }

    /**
     * Adresse d'un asset, seulement si elle désigne les releases du dépôt sur github.com ou api.github.com.
     *
     * @param  array<string, mixed>  $asset
     */
    private static function assetUrl(array $asset, string $repository): ?string
    {
        $prefixes = ['https://github.com/'.$repository.'/releases/download/', 'https://api.github.com/repos/'.$repository.'/releases/assets/'];

        foreach (['browser_download_url', 'url'] as $key) {
            $url = $asset[$key] ?? null;

            if (! is_string($url) || str_contains($url, '..') || preg_match('/[\x00-\x20\\\\@]/', $url) === 1) {
                continue;
            }

            foreach ($prefixes as $prefix) {
                if (str_starts_with($url, $prefix)) {
                    return $url;
                }
            }
        }

        return null;
    }

    private function request(): PendingRequest
    {
        $request = Http::withHeaders(['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28', 'User-Agent' => 'Kintai-Updater/1.0'])
            ->timeout((int) config('kintai.update.timeout', 15));

        $token = trim((string) config('kintai.support.github_token'));

        return $token === '' ? $request : $request->withToken($token);
    }
}
