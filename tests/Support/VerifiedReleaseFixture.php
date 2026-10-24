<?php

declare(strict_types=1);

namespace kintai\Tests\Support;

use kintai\Core\Services\GithubUpdateService;

/**
 * Simule une release publiée par release.yml pour GithubUpdateService : archive kintai-{tag}.zip (avec
 * release-manifest.json), fichier SHA256SUMS cohérent, et commit du tag côté « GitHub ».
 *
 * Requiert une propriété `$tmpDir` (dossier temporaire du test) sur la classe hôte.
 */
trait VerifiedReleaseFixture
{
    protected const RELEASE_COMMIT = '0123456789abcdef0123456789abcdef01234567';

    /** @return list<array{name: string, url: string}> Fichiers joints d'une release vérifiable. */
    protected function releaseAssets(string $tag): array
    {
        return [
            ['name' => GithubUpdateService::archiveName($tag), 'url' => 'https://example.test/assets/' . $tag . '/archive'],
            ['name' => GithubUpdateService::CHECKSUMS_ASSET, 'url' => 'https://example.test/assets/' . $tag . '/sums'],
        ];
    }

    /**
     * Téléchargeur qui sert l'archive (construite une seule fois par tag, pour que son empreinte soit stable)
     * ou le fichier SHA256SUMS correspondant.
     *
     * @param array<string, string>   $files      Fichiers de l'archive (chemin relatif => contenu)
     * @param (callable(): void)|null $onDownload Appelé à chaque téléchargement
     * @param string|null             $commit     Commit écrit dans le manifeste (défaut : celui du tag)
     */
    protected function verifiedDownloader(array $files, ?callable $onDownload = null, ?string $commit = null): \Closure
    {
        return function (string $url, string $dest, string $token) use ($files, $onDownload, $commit): bool {
            if ($onDownload !== null) {
                $onDownload();
            }
            if (preg_match('#/assets/([^/]+)/(archive|sums)$#', $url, $m) !== 1) {
                return false;
            }
            [$tag, $kind] = [$m[1], $m[2]];

            $cached = $this->tmpDir . '/../kintai-test-release-' . md5($this->tmpDir . $tag . ($commit ?? '') . serialize($files)) . '.zip';
            if (!is_file($cached)) {
                $zip = new \ZipArchive();
                $zip->open($cached, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
                $root = 'kintai-' . $tag;
                foreach ($files as $relative => $content) {
                    $zip->addFromString($root . '/' . $relative, $content);
                }
                $zip->addFromString($root . '/' . GithubUpdateService::RELEASE_MANIFEST, json_encode([
                    'tag' => $tag, 'commit' => $commit ?? self::RELEASE_COMMIT,
                ]));
                $zip->close();
            }

            if ($kind === 'archive') {
                return copy($cached, $dest);
            }
            return file_put_contents($dest, hash_file('sha256', $cached) . '  ' . GithubUpdateService::archiveName($tag) . "\n") !== false;
        };
    }

    /** Commit du tag tel que l'API GitHub le renverrait. */
    protected function commitResolver(?string $commit = null): \Closure
    {
        return fn(string $repo, string $token, string $tag): ?string => $commit ?? self::RELEASE_COMMIT;
    }

    /** Ajoute les fichiers joints vérifiables aux releases qui n'en déclarent pas. */
    protected function withAssets(array $releases): array
    {
        return array_map(
            fn(array $r): array => $r + ['assets' => $this->releaseAssets((string) ($r['tag_name'] ?? ''))],
            $releases
        );
    }

    /** Supprime les archives mises en cache par verifiedDownloader() pour ce test. */
    protected function clearCachedReleases(): void
    {
        foreach (glob($this->tmpDir . '/../kintai-test-release-*.zip') ?: [] as $file) {
            @unlink($file);
        }
    }
}
