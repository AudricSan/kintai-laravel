<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Log;

use Illuminate\Support\Facades\Artisan;

/**
 * Met à jour l'application depuis une Release GitHub taguée : télécharge l'archive construite par la release
 * (code + vendor/), en vérifie l'intégrité, synchronise les fichiers (en supprimant ceux qui ont disparu depuis la
 * dernière mise à jour appliquée par ce mécanisme) et lance les migrations en attente.
 *
 * Intégrité (audit du 13/10/2026, M10) : l'archive kintai-{tag}.zip n'est appliquée que si son empreinte SHA-256
 * correspond au fichier SHA256SUMS publié avec la release, et si son release-manifest.json désigne bien ce tag et
 * le commit vers lequel pointe le tag sur GitHub. Avant, le zipball source était extrait sans aucun contrôle, puis
 * `composer install` était lancé via shell_exec ; vendor/ est désormais fourni par l'archive (release.yml).
 * Limite : ces contrôles détectent une archive altérée, tronquée ou rattachée au mauvais tag ; ils ne protègent pas
 * d'un compte GitHub compromis, qui pourrait publier archive et empreinte cohérentes.
 */
final class GithubUpdateService
{
    /** Préfixes jamais écrasés ni supprimés, relatifs à BASE_PATH (style unix). */
    private const EXCLUDED_PREFIXES = [
        'storage/',
        '.git/',
        '.env',
    ];

    /**
     * Nombre de pages de l'API Releases GitHub interrogées au maximum. Les
     * releases sont triées par date de création décroissante : une longue
     * série d'alpha/beta peut repousser la dernière release stable au-delà
     * d'une seule page — voir fetchReleaseListData().
     */
    private const MAX_RELEASE_PAGES = 5;

    /** Fichier des empreintes publié avec chaque release (format sha256sum : « <hex>  <nom> »). */
    public const CHECKSUMS_ASSET = 'SHA256SUMS';
    /** Fichier placé à la racine de l'archive par release.yml : {"tag": "vX.Y.Z", "commit": "<sha>"}. */
    public const RELEASE_MANIFEST = 'release-manifest.json';
    private const RELEASES_PER_PAGE = 100;

    private string $basePath;
    private string $manifestFile;
    private string $updatesDir;
    private string $repo;
    private string $token;
    private ?string $lastError = null;

    /** Cache mémoire (durée de la requête) des listes de releases déjà récupérées, indexées par canal — évite de refaire un appel GitHub à chaque méthode publique appelée dans la même requête (checkLatestRelease() + getReleaseHistory()). */
    private array $releaseListCache = [];

    /**
     * @param \Closure|null $releaseFetcher fn(string $repo, string $token, int $page): ?array — une page de releases GitHub, ou null pour signaler un échec (surchargeable pour les tests)
     * @param \Closure|null $zipDownloader  fn(string $url, string $dest, string $token): bool — surchargeable pour les tests
     * @param \Closure|null $commitResolver fn(string $repo, string $token, string $tag): ?string — commit du tag (surchargeable pour les tests)
     * @param \Closure|null $fileFetcher    fn(string $repo, string $token, string $tag, string $path): ?string — contenu d'un fichier
     *                                     du dépôt à ce tag (surchargeable pour les tests)
     */
    public function __construct(
        private readonly UpdateService $updateService,
        private readonly BackupService $backup,
        private readonly AppSettingsService $settings,
        ?string $basePath = null,
        private readonly ?\Closure $releaseFetcher = null,
        private readonly ?\Closure $zipDownloader = null,
        private readonly ?\Closure $commitResolver = null,
        private readonly ?\Closure $fileFetcher = null,
    ) {
        $this->basePath = $basePath ?? BASE_PATH;
        $this->manifestFile = storage_path('app/update-files-manifest.json');
        $this->updatesDir = storage_path('app/updates');
        $this->repo = env('GITHUB_UPDATE_REPO', 'AudricSan/Kintai');
        $this->token = env('GITHUB_UPDATE_TOKEN', '');
    }

    /**
     * Interroge les Releases GitHub taguées et retient la plus récente
     * compatible avec le canal de mise à jour configuré (release/beta/alpha).
     * Retourne null si le repo n'est pas configuré, si la requête échoue, ou
     * si aucune release compatible n'existe. En cas d'échec technique (réseau,
     * quota GitHub, etc.), la raison est disponible via getLastCheckError() —
     * contrairement à "aucune release compatible", qui n'est pas une erreur.
     */
    public function checkLatestRelease(): ?array
    {
        $this->lastError = null;

        if ($this->repo === '') {
            $this->lastError = 'Aucun dépôt GitHub configuré (GITHUB_UPDATE_REPO).';
            return null;
        }

        $channel = $this->settings->updateChannel();
        $releases = $this->fetchReleaseListData($channel);
        if ($releases === null) {
            if ($this->lastError === null) {
                $this->lastError = 'La vérification a échoué pour une raison inconnue.';
            }
            return null;
        }

        $release = $this->selectReleaseForChannel($releases, $channel);
        if ($release === null || !isset($release['tag_name'], $release['zipball_url'])) {
            return null;
        }

        $current = $this->updateService->getCurrentVersion();
        $latest = ltrim((string) $release['tag_name'], 'v');

        return [
            'current_version' => $current,
            'latest_version'  => $latest,
            'has_update'      => VersionScheme::isNewer($latest, $current),
            'release_notes'   => $release['body'] ?? '',
            'release_url'     => $release['html_url'] ?? null,
            'published_at'    => $release['published_at'] ?? null,
            'zipball_url'     => $release['zipball_url'],
            'tag_name'        => $release['tag_name'],
            // Fichiers joints à la release : nom => URL de téléchargement (API, valable aussi pour un dépôt privé).
            'assets'          => self::assetUrls($release['assets'] ?? []),
        ];
    }

    /**
     * Raison technique du dernier échec de checkLatestRelease() (réseau, quota
     * GitHub API, aucune méthode HTTP disponible...), ou null si la dernière
     * vérification a réussi (avec ou sans mise à jour trouvée).
     */
    public function getLastCheckError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Condense des notes de version au format Keep a Changelog ("### Catégorie"
     * suivi de puces "- ...") à une seule puce par catégorie, pour un résumé
     * rapide sur la page de mise à jour — le lien "Voir sur GitHub" reste
     * disponible pour le détail complet.
     */
    public function condenseReleaseNotes(string $notes): string
    {
        if (trim($notes) === '') {
            return '';
        }

        $lines = preg_split('/\r\n|\r|\n/', trim($notes)) ?: [];
        $out = [];
        $skippingBullets = false;
        foreach ($lines as $line) {
            if (preg_match('/^#{1,6}\s/', $line) === 1) {
                $out[] = $line;
                $skippingBullets = false;
                continue;
            }
            if (preg_match('/^[-*]\s/', $line) === 1) {
                if ($skippingBullets) {
                    continue;
                }
                $out[] = $line;
                $skippingBullets = true;
                continue;
            }
            $out[] = $line;
        }

        return trim(implode("\n", $out));
    }

    /** URL de la page des releases GitHub du dépôt suivi (lien "voir sur GitHub"). */
    public function getRepoReleasesUrl(): string
    {
        return "https://github.com/{$this->repo}/releases";
    }

    /**
     * Parmi les releases GitHub disponibles, retient celles compatibles avec
     * le canal demandé :
     * - release : uniquement les releases publiées depuis `main`, non prerelease ;
     * - beta    : les releases publiées depuis `main` ou `beta` (exclut `alpha`) ;
     * - alpha   : toutes les releases, canal le plus permissif.
     *
     * Le canal se détermine via `target_commitish` (la branche source de la
     * release, renseignée par .github/workflows/release.yml) et non pas en
     * inspectant le tag : le schéma de version X.Y.Z en cascade (voir
     * docs/releasing.md) ne fait jamais apparaître les mots "-alpha"/"-beta"
     * dans le tag lui-même.
     */
    private function filterReleasesForChannel(array $releases, string $channel): array
    {
        return array_values(array_filter($releases, function (array $release) use ($channel): bool {
            $branch = (string) ($release['target_commitish'] ?? '');
            $isPrerelease = (bool) ($release['prerelease'] ?? false);

            return match ($channel) {
                'alpha' => true,
                'beta'  => $branch !== 'alpha',
                default => $branch === 'main' && !$isPrerelease,
            };
        }));
    }

    /** Retient, parmi les releases compatibles avec le canal, la plus récente au sens de version_compare(). */
    private function selectReleaseForChannel(array $releases, string $channel): ?array
    {
        $candidates = $this->sortedCandidatesForChannel($releases, $channel);

        return $candidates[0] ?? null;
    }

    /** Releases compatibles avec le canal, triées de la plus récente à la plus ancienne (version_compare()). */
    private function sortedCandidatesForChannel(array $releases, string $channel): array
    {
        $candidates = $this->filterReleasesForChannel($releases, $channel);

        usort($candidates, function (array $a, array $b): int {
            $va = ltrim((string) ($a['tag_name'] ?? ''), 'v');
            $vb = ltrim((string) ($b['tag_name'] ?? ''), 'v');
            return version_compare($vb, $va);
        });

        return $candidates;
    }

    /**
     * Historique des dernières releases compatibles avec $channel (la plus
     * récente en premier), condensées pour affichage — utilisé sur la page de
     * mise à jour pour donner un aperçu du changelog des canaux sur lesquels
     * l'Owner ne se trouve pas actuellement, avant de basculer.
     *
     * @return array<int, array{version:string, notes:string, release_url:?string, published_at:?string}>
     */
    public function getReleaseHistory(string $channel, int $limit = 5): array
    {
        $releases = $this->fetchReleaseListData($channel);
        if ($releases === null) {
            return [];
        }

        $candidates = array_slice($this->sortedCandidatesForChannel($releases, $channel), 0, $limit);

        return array_map(fn(array $release): array => [
            'version'      => ltrim((string) ($release['tag_name'] ?? ''), 'v'),
            'notes'        => $this->condenseReleaseNotes((string) ($release['body'] ?? '')),
            'release_url'  => $release['html_url'] ?? null,
            'published_at' => $release['published_at'] ?? null,
        ], $candidates);
    }

    /**
     * Applique la dernière mise à jour disponible : backup de sécurité,
     * téléchargement + synchronisation des fichiers, migrations, bump de
     * version (vendor/ est fourni par l'archive vérifiée, plus de composer).
     *
     * @param (callable(int, string): void)|null $onProgress reçoit (pourcentage 0-100, libellé de l'étape en cours)
     */
    public function applyUpdate(?callable $onProgress = null): array
    {
        $progress = $onProgress ?? function (int $percent, string $label): void {};

        $progress(0, __('backup_update_step_check'));
        $release = $this->checkLatestRelease();
        if ($release === null || !$release['has_update']) {
            return ['ok' => false, 'error' => 'no_update_available'];
        }

        $tag = $release['tag_name'];
        $safeTag = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $tag);
        $extractDir = null;
        $downloadPath = null;

        try {
            $progress(10, __('backup_update_step_backup'));
            $this->createSafetyBackups($tag);

            // Archive construite par release.yml (code + vendor/) et son empreinte : une release sans elles
            // (antérieure à ce mécanisme, ou publication incomplète) n'est pas appliquée.
            $archiveName = self::archiveName($tag);
            $archiveUrl  = $release['assets'][$archiveName] ?? null;
            $sumsUrl     = $release['assets'][self::CHECKSUMS_ASSET] ?? null;
            if ($archiveUrl === null || $sumsUrl === null) {
                return ['ok' => false, 'error' => 'release_archive_missing'];
            }

            $progress(25, __('backup_update_step_download'));
            $downloadPath = $this->updatesDir . '/download_' . $safeTag . '.zip';
            $sumsPath     = $this->updatesDir . '/download_' . $safeTag . '.sha256';
            if (!$this->downloadZip($archiveUrl, $downloadPath) || !$this->downloadZip($sumsUrl, $sumsPath)) {
                return ['ok' => false, 'error' => 'download_failed'];
            }

            $progress(40, __('backup_update_step_verify'));
            $checksumOk = self::checksumMatches($downloadPath, (string) @file_get_contents($sumsPath), $archiveName);
            @unlink($sumsPath);
            if (!$checksumOk) {
                Log::warning('update_checksum_mismatch', ['tag' => $tag]);
                return ['ok' => false, 'error' => 'checksum_failed'];
            }

            $progress(45, __('backup_update_step_extract'));
            $extractDir = $this->updatesDir . '/extract_' . $safeTag;
            $sourceRoot = $this->extractZip($downloadPath, $extractDir);
            if ($sourceRoot === null) {
                return ['ok' => false, 'error' => 'extract_failed'];
            }

            // L'archive doit avoir été construite pour ce tag, à partir du commit vers lequel il pointe.
            $tagCommit = $this->resolveTagCommit($tag);
            if (!self::manifestMatches($sourceRoot, $tag, $tagCommit)) {
                Log::warning('update_commit_mismatch', ['tag' => $tag, 'tag_commit' => $tagCommit]);
                return ['ok' => false, 'error' => 'commit_mismatch'];
            }

            $sync = $this->syncFiles($sourceRoot, function (int $done, int $total) use ($progress): void {
                $pct = $total > 0 ? (int) round(55 + 20 * ($done / $total)) : 55;
                $progress($pct, __('backup_update_step_sync', ['done' => $done, 'total' => $total]));
            });

            $progress(80, __('backup_update_step_migrate'));
            $migrationsApplied = count($this->updateService->getPendingMigrations());
            Artisan::call('migrate', ['--force' => true]);

            $this->updateService->setCurrentVersion($release['latest_version']);

            $progress(100, __('backup_update_step_done'));

            return [
                'ok'                 => true,
                'version'            => $release['latest_version'],
                'files_copied'       => $sync['copied'],
                'files_deleted'      => $sync['deleted'],
                'migrations_applied' => $migrationsApplied,
                // vendor/ est fourni par l'archive : plus de composer pendant la mise à jour.
                'composer'           => 'bundled',
            ];
        } finally {
            if ($downloadPath !== null && file_exists($downloadPath)) {
                unlink($downloadPath);
            }
            if ($extractDir !== null && is_dir($extractDir)) {
                $this->removeDir($extractDir);
            }
        }
    }

    private function createSafetyBackups(string $tag): void
    {
        $label = 'avant-maj-' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $tag);
        $this->backup->create('[auto] Avant mise à jour vers ' . $tag);
        $this->backup->createCodeSnapshot($label, $this->basePath);
    }

    /**
     * Copie chaque fichier de $sourceRoot vers $this->basePath, puis supprime
     * les fichiers qui étaient gérés par la mise à jour précédente (présents
     * dans l'ancien manifeste) mais absents de la nouvelle release.
     *
     * @param (callable(int, int): void)|null $onFileProgress reçoit (fichiers traités, total)
     * @return array{copied:int, deleted:int}
     */
    private function syncFiles(string $sourceRoot, ?callable $onFileProgress = null): array
    {
        $newManifest = [];
        $copied = 0;

        $total = iterator_count(new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceRoot, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        ));

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceRoot, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                continue;
            }

            $relative = str_replace('\\', '/', $iterator->getSubPathname());
            // Données et configuration locales (storage/, .env, config/database.local.php) : jamais écrasées,
            // même si une archive en contenait.
            if ($this->isExcluded($relative)) {
                continue;
            }
            $newManifest[] = $relative;

            $target = $this->basePath . '/' . $relative;
            $targetDir = dirname($target);
            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0755, true);
            }
            copy($item->getRealPath(), $target);
            $copied++;

            if ($onFileProgress !== null) {
                $onFileProgress($copied, $total);
            }
        }

        $oldManifest = $this->readManifest();
        $deleted = 0;
        foreach (array_diff($oldManifest, $newManifest) as $relative) {
            if ($this->isExcluded($relative)) {
                continue;
            }
            $path = $this->basePath . '/' . $relative;
            if (is_file($path)) {
                unlink($path);
                $this->pruneEmptyParents(dirname($path));
                $deleted++;
            }
        }

        $this->writeManifest($newManifest);

        return ['copied' => $copied, 'deleted' => $deleted];
    }

    private function isExcluded(string $relative): bool
    {
        foreach (self::EXCLUDED_PREFIXES as $prefix) {
            if (str_starts_with($relative, $prefix)) {
                return true;
            }
        }
        return false;
    }

    private function pruneEmptyParents(string $dir): void
    {
        $root = rtrim($this->basePath, '/');
        while (str_starts_with($dir, $root . '/') && $dir !== $root) {
            $entries = @scandir($dir);
            if ($entries === false || count(array_diff($entries, ['.', '..'])) > 0) {
                break;
            }
            rmdir($dir);
            $dir = dirname($dir);
        }
    }

    private function readManifest(): array
    {
        if (!file_exists($this->manifestFile)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($this->manifestFile), true);
        return is_array($data) ? $data : [];
    }

    private function writeManifest(array $manifest): void
    {
        $dir = dirname($this->manifestFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($this->manifestFile, json_encode(array_values($manifest), JSON_PRETTY_PRINT));
    }

    /** Nom de l'archive construite par release.yml pour un tag. */
    public static function archiveName(string $tag): string
    {
        return 'kintai-' . $tag . '.zip';
    }

    /**
     * @param list<array<string, mixed>> $assets Fichiers joints d'une release GitHub
     * @return array<string, string> nom => URL (API de l'asset si disponible, sinon URL de téléchargement)
     */
    private static function assetUrls(array $assets): array
    {
        $urls = [];
        foreach ($assets as $asset) {
            $name = (string) ($asset['name'] ?? '');
            $url  = (string) ($asset['url'] ?? ($asset['browser_download_url'] ?? ''));
            if ($name !== '' && $url !== '') {
                $urls[$name] = $url;
            }
        }
        return $urls;
    }

    /** L'empreinte SHA-256 de $file correspond-elle à la ligne de $name dans le contenu de SHA256SUMS ? */
    public static function checksumMatches(string $file, string $checksums, string $name): bool
    {
        if (!is_file($file)) {
            return false;
        }
        foreach (preg_split('/\R/', $checksums) ?: [] as $line) {
            if (preg_match('/^([0-9a-fA-F]{64})\s+\*?(.+)$/', trim($line), $m) === 1 && trim($m[2]) === $name) {
                return hash_equals(strtolower($m[1]), (string) hash_file('sha256', $file));
            }
        }
        return false;
    }

    /** Le release-manifest.json de l'archive désigne-t-il ce tag et ce commit ? */
    public static function manifestMatches(string $sourceRoot, string $tag, ?string $tagCommit): bool
    {
        if ($tagCommit === null || $tagCommit === '') {
            return false;
        }
        $manifest = json_decode((string) @file_get_contents($sourceRoot . '/' . self::RELEASE_MANIFEST), true);
        return is_array($manifest)
            && ($manifest['tag'] ?? null) === $tag
            && is_string($manifest['commit'] ?? null)
            && hash_equals(strtolower($tagCommit), strtolower($manifest['commit']));
    }

    /**
     * Version du contrat de bundle (kintai\Contract\Contract::VERSION) fournie par la release de ce tag, lue dans
     * son code sur GitHub, ou null si elle est illisible (réseau, release antérieure au contrat versionné).
     */
    public function contractVersionAt(string $tag): ?string
    {
        $path = 'app/Contract/Contract.php';
        if ($this->fileFetcher !== null) {
            $source = ($this->fileFetcher)($this->repo, $this->token, $tag, $path);
        } else {
            $headers = ['User-Agent: Kintai-UpdateCheck/1.0'];
            if ($this->token !== '') {
                $headers[] = 'Authorization: Bearer ' . $this->token;
            }
            $source = $this->httpGet('https://raw.githubusercontent.com/' . $this->repo . '/' . rawurlencode($tag) . '/' . $path, $headers, 10);
        }
        return is_string($source) && preg_match("/const VERSION = '(\\d+\\.\\d+\\.\\d+)'/", $source, $m) === 1 ? $m[1] : null;
    }

    /** Commit vers lequel pointe le tag sur GitHub (GET /repos/{repo}/commits/{tag}), ou null. */
    private function resolveTagCommit(string $tag): ?string
    {
        if ($this->commitResolver !== null) {
            return ($this->commitResolver)($this->repo, $this->token, $tag);
        }

        $headers = [
            'User-Agent: Kintai-UpdateCheck/1.0',
            'Accept: application/vnd.github+json',
        ];
        if ($this->token !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }
        $response = $this->httpGet('https://api.github.com/repos/' . $this->repo . '/commits/' . rawurlencode($tag), $headers, 10);
        $data = $response !== null ? json_decode($response, true) : null;
        $sha = is_array($data) ? ($data['sha'] ?? null) : null;
        return is_string($sha) && preg_match('/^[0-9a-f]{40}$/', $sha) === 1 ? $sha : null;
    }

    private function extractZip(string $zipPath, string $extractDir): ?string
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return null;
        }
        try {
            \App\Support\SafeZip::extract($zip, $extractDir);
        } catch (\RuntimeException) {
            return null;
        } finally {
            $zip->close();
        }

        $dirs = glob($extractDir . '/*', GLOB_ONLYDIR);
        if ($dirs === false || count($dirs) !== 1) {
            return null;
        }
        return $dirs[0];
    }

    private function removeDir(string $dir): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getRealPath()) : unlink($item->getRealPath());
        }
        rmdir($dir);
    }

    /**
     * Récupère les releases GitHub, en paginant au besoin.
     *
     * L'API GitHub trie les releases par date de création décroissante. Une
     * longue série d'alpha/beta (chacune étant une release distincte, voir
     * .github/workflows/release.yml) peut repousser la dernière release
     * stable au-delà de la première page : on continue donc à paginer tant
     * qu'aucune release compatible avec $channel n'a été trouvée dans les
     * pages déjà récupérées, jusqu'à MAX_RELEASE_PAGES.
     *
     * @return array|null Liste des releases GitHub (voir GET /repos/{repo}/releases), ou null si la première page échoue.
     */
    private function fetchReleaseListData(string $channel): ?array
    {
        if (array_key_exists($channel, $this->releaseListCache)) {
            return $this->releaseListCache[$channel];
        }

        return $this->releaseListCache[$channel] = $this->fetchReleaseListDataUncached($channel);
    }

    private function fetchReleaseListDataUncached(string $channel): ?array
    {
        $headers = [
            'User-Agent: Kintai-UpdateCheck/1.0',
            'Accept: application/vnd.github+json',
        ];
        if ($this->token !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }

        $all = [];
        for ($page = 1; $page <= self::MAX_RELEASE_PAGES; $page++) {
            $data = $this->releaseFetcher !== null
                ? ($this->releaseFetcher)($this->repo, $this->token, $page)
                : $this->fetchReleasePage($page, $headers);

            if ($data === null) {
                return $all !== [] ? $all : null;
            }

            $all = [...$all, ...$data];

            if ($this->selectReleaseForChannel($all, $channel) !== null) {
                break;
            }
            if (count($data) < self::RELEASES_PER_PAGE) {
                break;
            }
        }

        return $all;
    }

    /** @return array|null Une page de releases GitHub, ou null si la requête échoue. */
    private function fetchReleasePage(int $page, array $headers): ?array
    {
        $url = "https://api.github.com/repos/{$this->repo}/releases?per_page=" . self::RELEASES_PER_PAGE . "&page={$page}";

        $response = $this->httpGet($url, $headers, 10);
        if ($response === null) {
            return null;
        }

        $data = json_decode($response, true);
        if (!self::isValidReleaseList($data)) {
            $this->lastError = is_array($data) && isset($data['message'])
                ? 'GitHub : ' . $data['message']
                : 'Réponse GitHub inattendue.';
            Log::warning('update_check_invalid_response', [
                'url'            => $url,
                'github_message' => is_array($data) ? ($data['message'] ?? null) : null,
            ]);
            return null;
        }

        return $data;
    }

    /**
     * Effectue une requête GET HTTPS en préférant curl (plus fiable sur les
     * hébergements mutualisés où `allow_url_fopen` est parfois désactivé),
     * avec repli sur les wrappers de flux PHP si curl n'est pas chargé.
     * Renseigne $this->lastError et logue la raison exacte en cas d'échec —
     * jusqu'ici la vérification échouait entièrement en silence (@file_get_contents),
     * rendant le diagnostic impossible sur un hébergement où on ne voit pas les logs.
     */
    private function httpGet(string $url, array $headers, int $timeoutSeconds): ?string
    {
        if (function_exists('curl_init')) {
            return $this->httpGetViaCurl($url, $headers, $timeoutSeconds);
        }

        if (!(bool) ini_get('allow_url_fopen')) {
            $this->lastError = "Aucune méthode HTTP disponible sur ce serveur : l'extension curl est absente et allow_url_fopen est désactivé.";
            Log::warning('update_check_no_http_method', ['url' => $url]);
            return null;
        }

        return $this->httpGetViaStreamWrapper($url, $headers, $timeoutSeconds);
    }

    private function httpGetViaCurl(string $url, array $headers, int $timeoutSeconds): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $timeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);
            $this->lastError = 'Erreur curl : ' . $error;
            Log::warning('update_check_curl_failed', ['url' => $url, 'curl_error' => $error]);
            return null;
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status >= 400) {
            $this->lastError = "GitHub a répondu avec le statut HTTP {$status}.";
        }

        return (string) $body;
    }

    private function httpGetViaStreamWrapper(string $url, array $headers, int $timeoutSeconds): ?string
    {
        $context = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'header'        => implode("\r\n", $headers),
                'timeout'       => $timeoutSeconds,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            $error = error_get_last();
            $message = $error['message'] ?? 'raison inconnue';
            $this->lastError = 'Échec de la requête HTTP : ' . $message;
            Log::warning('update_check_stream_failed', ['url' => $url, 'php_error' => $message]);
            return null;
        }

        return $response;
    }

    /**
     * GitHub renvoie un objet d'erreur (ex: {"message": "API rate limit exceeded..."})
     * pour les réponses en erreur (rate limit, auth, 404...) au lieu de la liste des
     * releases attendue. json_decode(..., true) le transforme en tableau associatif,
     * donc is_array() seul ne suffit pas à l'écarter : on exige une liste (tableau
     * indexé séquentiellement), ce que sera toujours une vraie réponse de releases.
     */
    private static function isValidReleaseList(mixed $data): bool
    {
        return is_array($data) && array_is_list($data);
    }

    private function downloadZip(string $url, string $destination): bool
    {
        $dir = dirname($destination);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if ($this->zipDownloader !== null) {
            return ($this->zipDownloader)($url, $destination, $this->token);
        }

        // Asset de release par l'API : sans cet en-tête, GitHub renvoie ses métadonnées JSON au lieu du fichier.
        $headers = ['User-Agent: Kintai-UpdateCheck/1.0', 'Accept: application/octet-stream'];
        if ($this->token !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }

        if (function_exists('curl_init')) {
            return $this->downloadZipViaCurl($url, $destination, $headers);
        }

        if (!(bool) ini_get('allow_url_fopen')) {
            return false;
        }

        return $this->downloadZipViaStreamWrapper($url, $destination, $headers);
    }

    private function downloadZipViaCurl(string $url, string $destination, array $headers): bool
    {
        $out = fopen($destination, 'wb');
        if ($out === false) {
            return false;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $out,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($out);

        if ($ok === false || $status >= 400) {
            @unlink($destination);
            return false;
        }

        return true;
    }

    private function downloadZipViaStreamWrapper(string $url, string $destination, array $headers): bool
    {
        $context = stream_context_create([
            'http' => [
                'method'          => 'GET',
                'header'          => implode("\r\n", $headers),
                'timeout'         => 60,
                'ignore_errors'   => true,
                'follow_location' => 1,
            ],
        ]);

        $in = @fopen($url, 'rb', false, $context);
        if ($in === false) {
            return false;
        }

        $out = fopen($destination, 'wb');
        if ($out === false) {
            fclose($in);
            return false;
        }

        $ok = stream_copy_to_stream($in, $out) !== false;
        fclose($in);
        fclose($out);

        return $ok;
    }
}
