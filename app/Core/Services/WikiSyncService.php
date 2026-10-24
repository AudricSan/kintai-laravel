<?php

declare(strict_types=1);

namespace kintai\Core\Services;

/**
 * Clone/met à jour le wiki GitHub du projet dans `.wiki/` (gitignoré) par
 * simple HTTP, sans dépendre du binaire `git` ni de `exec()`/`shell_exec()`
 * (souvent indisponibles en hébergement mutualisé) — même approche que
 * GithubUpdateService pour l'auto-mise à jour de l'application.
 *
 * Chaque page est récupérée via `raw.githubusercontent.com/wiki/{repo}/...`,
 * la convention GitHub pour servir le contenu brut d'une page de wiki. La
 * liste des pages à synchroniser est déduite du `_Sidebar.md` distant (donc
 * toujours à jour même si de nouvelles pages sont ajoutées au wiki), pour
 * chacune des langues supportées par WikiContentService.
 */
final class WikiSyncService
{
    private string $wikiPath;
    private string $repo;

    /**
     * @param \Closure|null $fetcher fn(string $url): array{ok: bool, status: int, body: ?string} — surchargeable pour les tests
     */
    public function __construct(
        string $wikiPath = '',
        private readonly ?\Closure $fetcher = null,
    ) {
        $basePath = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 3);
        $this->wikiPath = $wikiPath !== '' ? $wikiPath : $basePath . '/.wiki';
        $this->repo = env('GITHUB_UPDATE_REPO', 'AudricSan/Kintai');
    }

    /**
     * Récupère (ou met à jour) toutes les pages listées par le _Sidebar.md du
     * wiki distant, pour chaque langue supportée. Idempotent : une page dont
     * le contenu n'a pas changé n'est pas réécrite. Fonctionne aussi bien pour
     * un premier clonage (dossier absent) que pour une mise à jour (dossier
     * déjà présent) — c'est le même appel dans les deux cas.
     *
     * @return array{ok: bool, added: int, updated: int, unchanged: int, skipped: int, errors: string[]}
     */
    public function sync(): array
    {
        $result = ['ok' => true, 'added' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'errors' => []];

        $sidebar = $this->fetch($this->rawUrl('_Sidebar.md'));
        if (!$sidebar['ok'] || $sidebar['body'] === null) {
            $result['ok'] = false;
            $result['errors'][] = 'Impossible de récupérer _Sidebar.md depuis le wiki GitHub (HTTP ' . $sidebar['status'] . ').';
            return $result;
        }

        $this->ensureDir($this->wikiPath);
        $this->writeIfChanged($this->wikiPath . '/_Sidebar.md', $sidebar['body'], $result);

        $slugs = $this->flattenSlugs(WikiContentService::parseSidebarMarkdown($sidebar['body']));

        // L'anglais d'abord : la ligne de langue (🌐) de chaque page anglaise donne le nom du fichier traduit.
        $langs = WikiContentService::supportedLangs();
        usort($langs, static fn (string $a, string $b): int => ($b === 'en') <=> ($a === 'en'));

        /** @var array<string, array<string, string>> $localNames slug anglais => [langue => nom de fichier] */
        $localNames = [];

        foreach ($langs as $lang) {
            foreach ($slugs as $slug) {
                // Le wiki GitHub est à plat : toutes les pages à la racine, avec un nom de fichier propre à la langue.
                $fileSlug   = $lang === 'en' ? $slug : ($localNames[$slug][$lang] ?? $slug);
                $remotePath = $fileSlug . '.md';
                $fetched = $this->fetch($this->rawUrl($remotePath));

                if (!$fetched['ok']) {
                    // 404 = page pas (encore) traduite dans cette langue : ce n'est pas une erreur.
                    if ($fetched['status'] === 404) {
                        $result['skipped']++;
                    } else {
                        $result['errors'][] = "{$remotePath} : HTTP {$fetched['status']}";
                    }
                    continue;
                }

                if ($lang === 'en') {
                    $localNames[$slug] = WikiContentService::localizedSlugsFromPage((string) $fetched['body']);
                }

                $this->writeIfChanged($this->wikiPath . '/' . $fileSlug . '.md', (string) $fetched['body'], $result);
            }
        }

        $result['ok'] = $result['errors'] === [];

        return $result;
    }

    /**
     * @param array<string, string[]> $groups
     * @return string[]
     */
    private function flattenSlugs(array $groups): array
    {
        $slugs = [];
        foreach ($groups as $groupSlugs) {
            foreach ($groupSlugs as $slug) {
                $slugs[] = $slug;
            }
        }

        return array_values(array_unique($slugs));
    }

    private function writeIfChanged(string $path, string $content, array &$result): void
    {
        $existed = file_exists($path);
        $current = $existed ? file_get_contents($path) : null;

        if ($current === $content) {
            $result['unchanged']++;
            return;
        }

        file_put_contents($path, $content);
        $existed ? $result['updated']++ : $result['added']++;
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    /** Les noms de fichiers japonais ou accentués sont encodés en pourcentage, segment par segment. */
    private function rawUrl(string $path): string
    {
        $encoded = implode('/', array_map('rawurlencode', explode('/', $path)));

        return 'https://raw.githubusercontent.com/wiki/' . $this->repo . '/' . $encoded;
    }

    /**
     * @return array{ok: bool, status: int, body: ?string}
     */
    private function fetch(string $url): array
    {
        if ($this->fetcher !== null) {
            return ($this->fetcher)($url);
        }

        $context = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'header'        => "User-Agent: Kintai-WikiSync/1.0\r\n",
                'timeout'       => 10,
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        $status = 0;

        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $header, $m)) {
                $status = (int) $m[1];
            }
        }

        return [
            'ok'     => $body !== false && $status >= 200 && $status < 300,
            'status' => $status,
            'body'   => $body !== false ? $body : null,
        ];
    }
}
