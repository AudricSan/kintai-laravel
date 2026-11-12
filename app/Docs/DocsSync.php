<?php

namespace App\Docs;

use App\Settings\AppSettings;
use App\Support\GithubRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Copie le wiki GitHub du projet dans `storage/app/docs` par simple HTTP (`raw.githubusercontent.com/wiki/{dépôt}/{page}.md`),
 * sans `git` ni terminal. Le sommaire (`_Sidebar.md`) donne la liste des pages ; une page inchangée n'est pas réécrite ;
 * une page 404 est ignorée, une autre erreur est comptée. Contrôles d'intégrité avant d'écrire : taille maximale, UTF-8 valide,
 * aucun octet nul, nom de page valide. Les pages qui ont quitté le sommaire sont supprimées quand la synchronisation est complète.
 */
class DocsSync
{
    /** Nombre maximal de pages synchronisées (protège d'un sommaire démesuré). */
    public const MAX_PAGES = 150;

    private const BASE_URL = 'https://raw.githubusercontent.com/wiki/';

    public function __construct(private readonly DocsStore $store, private readonly AppSettings $settings) {}

    /**
     * @return array{ok: bool, added: int, updated: int, unchanged: int, skipped: int, removed: int, errors: list<string>}
     */
    public function sync(): array
    {
        $result = ['ok' => false, 'added' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'removed' => 0, 'errors' => []];
        $repository = trim((string) config('kintai.docs.repository'));

        if (! GithubRepository::isValid($repository)) {
            $result['errors'][] = 'The documentation repository is not configured (kintai.docs.repository).';

            return $result;
        }

        $sidebar = $this->fetch($repository, DocsStore::SIDEBAR, DocsStore::SIDEBAR_MAX_BYTES);

        if (is_array($sidebar)) {
            $result['errors'][] = '_Sidebar: '.$sidebar['error'];

            return $result;
        }

        $slugs = [];
        foreach (DocsStore::parseSidebar($sidebar) as $group) {
            foreach ($group['items'] as $item) {
                $slugs[$item['slug']] = true;
            }
        }
        $slugs = array_slice(array_keys($slugs), 0, self::MAX_PAGES);

        if ($slugs === []) {
            $result['errors'][] = '_Sidebar: no page listed.';

            return $result;
        }

        $result[$this->store->write(DocsStore::SIDEBAR, $sidebar)]++;

        foreach ($slugs as $slug) {
            $page = $this->fetch($repository, $slug, DocsStore::PAGE_MAX_BYTES);

            if (! is_array($page)) {
                $result[$this->store->write($slug, $page)]++;
            } elseif ($page['status'] === 404) {
                $result['skipped']++;
            } else {
                $result['errors'][] = $slug.': '.$page['error'];
            }
        }

        $result['ok'] = $result['errors'] === [];

        if ($result['ok']) {
            $result['removed'] = $this->store->prune($slugs);
            $this->settings->set('docs.last_sync_at', now()->toIso8601String());
        }

        return $result;
    }

    /**
     * @return string|array{status: int, error: string} Le contenu contrôlé, ou l'erreur.
     */
    private function fetch(string $repository, string $name, int $maxBytes): string|array
    {
        $url = self::BASE_URL.$repository.'/'.rawurlencode($name).'.md';

        try {
            $response = Http::timeout(15)->withHeaders(['User-Agent' => 'Kintai-DocsSync/1.0'])->get($url);
        } catch (ConnectionException) {
            return ['status' => 0, 'error' => 'connection failed'];
        }

        if (! $response->successful()) {
            return ['status' => $response->status(), 'error' => 'HTTP '.$response->status()];
        }

        $body = $response->body();

        if (strlen($body) > $maxBytes) {
            return ['status' => 200, 'error' => 'too large'];
        }

        if (str_contains($body, "\0") || ! mb_check_encoding($body, 'UTF-8')) {
            return ['status' => 200, 'error' => 'invalid encoding'];
        }

        // Sans BOM et avec des fins de ligne uniformes : une page inchangée reste identique d'une synchronisation à l'autre.
        return str_replace("\r\n", "\n", (string) preg_replace('/^\xEF\xBB\xBF/', '', $body));
    }
}
