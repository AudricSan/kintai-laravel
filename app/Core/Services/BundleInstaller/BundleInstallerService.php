<?php

declare(strict_types=1);

namespace kintai\Core\Services\BundleInstaller;

use kintai\Core\InstalledBundleAutoloader;
use kintai\Core\BundleContract\BundleManifest;
use kintai\Core\Database\BundleMigrationRunner;
use kintai\Core\InstalledBundleManifestStore;
use kintai\Core\Repositories\InstalledBundleRepositoryInterface;
use kintai\Core\Services\HttpFetcher;
use kintai\Core\Services\Log;
use kintai\Core\Services\UpdateService;

/**
 * Télécharge, vérifie et installe un bundle depuis une release GitHub taguée
 * (jamais de `git clone`/`pull` — hébergements mutualisés sans binaire git
 * garanti, voir docs/architecture.md). Frère de GithubUpdateService : même
 * conventions (tag `v{version}`, zipball d'une release, vérification "un
 * seul dossier racine" après extraction), mêmes points d'injection par
 * closure pour les tests, mais installe un bundle tiers dans
 * storage/bundles/ au lieu de mettre à jour l'application elle-même.
 */
final class BundleInstallerService
{
    private const DOWNLOAD_TIMEOUT_SECONDS = 60;

    private ?string $lastError = null;

    /**
     * @param \Closure|null $releaseFetcher fn(string $repositoryUrl, string $version): ?string — l'URL du zipball de la release taguée v{version}, ou null en cas d'échec (surchargeable pour les tests)
     * @param \Closure|null $zipDownloader  fn(string $url, string $destination): bool — surchargeable pour les tests
     */
    public function __construct(
        private readonly UpdateService $updateService,
        private readonly InstalledBundleRepositoryInterface $installedBundles,
        private readonly InstalledBundleManifestStore $manifestStore = new InstalledBundleManifestStore(),
        private readonly ?string $bundlesDir = null,
        private readonly ?\Closure $releaseFetcher = null,
        private readonly ?\Closure $zipDownloader = null,
        private readonly HttpFetcher $http = new HttpFetcher(),
        private readonly ?BundleMigrationRunner $migrationRunner = null,
        // Droits du rôle Manager pour les catégories déclarées, à la première installation d'un bundle.
        private readonly ?BundlePermissionGranter $permissionGranter = null,
    ) {
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * @param (callable(int, string): void)|null $onProgress reçoit (pourcentage 0-100, libellé de l'étape en cours)
     * @param string|null $expectedCommit commit épinglé par le registry pour cette version (40 hex) : l'archive téléchargée
     *        doit y correspondre, sinon l'installation est refusée avant toute extraction. null = registry sans épinglage.
     */
    public function install(string $slug, string $repositoryUrl, string $version, ?string $sourceRegistryUrl = null, ?callable $onProgress = null, ?string $expectedCommit = null): BundleInstallResult
    {
        return $this->run($slug, $repositoryUrl, $version, $sourceRegistryUrl, dryRun: false, onProgress: $onProgress, expectedCommit: $expectedCommit);
    }

    /** Identifiant d'un bundle : devient un nom de dossier sous storage/bundles/, donc jamais de `/`, `..` ni majuscule. */
    public static function isValidSlug(string $slug): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/', $slug) === 1;
    }

    /** Numéro de version (X.Y.Z, suffixe de pré-version optionnel) : même raison, il devient un nom de dossier. */
    public static function isValidVersion(string $version): bool
    {
        return preg_match('/^\d{1,5}\.\d{1,5}\.\d{1,5}(?:-[0-9A-Za-z][0-9A-Za-z.-]{0,30})?$/', $version) === 1;
    }

    /** Pose lastError et renvoie false si le couple slug/version ne peut pas servir de chemin disque. */
    private function acceptsIdentity(string $slug, ?string $version = null): bool
    {
        if (!self::isValidSlug($slug)) {
            $this->lastError = "Identifiant de bundle invalide : « {$slug} ».";
            return false;
        }
        if ($version !== null && !self::isValidVersion($version)) {
            $this->lastError = "Numéro de version invalide : « {$version} ».";
            return false;
        }
        return true;
    }

    public function dryRun(string $slug, string $repositoryUrl, string $version, ?string $expectedCommit = null): BundleInstallResult
    {
        return $this->run($slug, $repositoryUrl, $version, null, dryRun: true, expectedCommit: $expectedCommit);
    }

    /**
     * Retire un bundle installé dynamiquement : dossier storage/bundles/{slug}
     * (toutes versions confondues), entrée du manifeste et ligne en base.
     * Un bundle du monorepo (app/Bundles/, jamais passé par install()) n'a
     * pas de ligne dans installedBundles et est donc refusé ici — sa
     * désactivation reste le rôle de BundleSettingsController, pas de lui.
     */
    public function uninstall(string $slug): bool
    {
        $this->lastError = null;

        if (!$this->acceptsIdentity($slug)) {
            return false;
        }

        if ($this->installedBundles->find($slug) === null) {
            $this->lastError = "Le bundle {$slug} n'est pas géré par l'installateur (bundle du monorepo ou déjà désinstallé).";
            return false;
        }

        $this->removeDirIfExists($this->bundlesDir() . "/{$slug}");
        $this->manifestStore->remove($slug);
        $this->installedBundles->delete($slug);
        $this->migrationRunner?->forgetBundle($slug);

        Log::info('bundle_uninstalled', ['slug' => $slug]);

        return true;
    }

    /**
     * Repointe la version active vers une version déjà présente sur disque
     * (pas de retéléchargement) — utile si une mise à jour casse quelque chose.
     */
    public function rollback(string $slug, string $version): bool
    {
        $this->lastError = null;
        if (!$this->acceptsIdentity($slug, $version)) {
            return false;
        }

        $bundleDir = $this->bundlesDir() . "/{$slug}/{$version}";
        if (!is_dir($bundleDir)) {
            $this->lastError = "La version {$version} de {$slug} n'est pas présente sur ce serveur.";
            return false;
        }

        $existing = $this->installedBundles->find($slug);
        $this->manifestStore->setActiveVersion($slug, $version);
        $this->installedBundles->upsert($slug, $version, $existing['source_registry_url'] ?? null);

        return true;
    }

    /**
     * @param (callable(int, string): void)|null $onProgress
     */
    private function run(string $slug, string $repositoryUrl, string $version, ?string $sourceRegistryUrl, bool $dryRun, ?callable $onProgress = null, ?string $expectedCommit = null): BundleInstallResult
    {
        $this->lastError = null;
        $progress = $onProgress ?? function (int $percent, string $label): void {};

        // Avant tout chemin disque : sinon un slug « ../.. » déplaçait la création, l'extraction et la
        // suppression du dossier de préparation hors de storage/bundles.
        if (!$this->acceptsIdentity($slug, $version)) {
            return BundleInstallResult::failure($this->lastError);
        }

        $stagingContainer = $this->bundlesDir() . "/{$slug}/.staging/{$version}";
        $zipPath = $stagingContainer . '.zip';

        $progress(10, "Résolution de la release {$version}...");
        $downloadUrl = $this->resolveDownloadUrl($repositoryUrl, $version);
        if ($downloadUrl === null) {
            return BundleInstallResult::failure($this->lastError ?? "Impossible de résoudre la release {$version} pour {$repositoryUrl}.");
        }

        $progress(30, 'Téléchargement de l\'archive...');
        if (!$this->download($downloadUrl, $zipPath)) {
            return BundleInstallResult::failure($this->lastError ?? "Échec du téléchargement de l'archive.");
        }

        // Avant toute extraction : l'archive doit être celle du commit que le registry a épinglé.
        if ($expectedCommit !== null) {
            $progress(50, "Vérification de l'empreinte du commit...");
            $commitError = ArchiveCommitVerifier::verify($zipPath, $expectedCommit);
            if ($commitError !== null) {
                @unlink($zipPath);
                Log::warning('bundle_commit_mismatch', ['slug' => $slug, 'version' => $version, 'expected' => $expectedCommit]);
                return BundleInstallResult::failure($commitError);
            }
        } elseif (!$dryRun) {
            // Registry sans épinglage (tiers) : on installe comme avant, mais la trace en reste.
            Log::warning('bundle_install_unpinned', ['slug' => $slug, 'version' => $version]);
        }

        $progress(60, 'Vérification du bundle...');
        $verified = $this->verifyAndExtract($zipPath, $slug, $version, $stagingContainer);
        @unlink($zipPath);

        if ($verified === null) {
            $this->removeDirIfExists($stagingContainer);
            return BundleInstallResult::failure($this->lastError ?? 'Vérification du bundle échouée.');
        }

        [$manifest, $extractedRoot] = $verified;

        if ($dryRun) {
            $this->removeDirIfExists($stagingContainer);
            $progress(100, 'Vérification terminée.');
            return BundleInstallResult::dryRunOk($manifest);
        }

        $progress(90, 'Activation du bundle...');
        try {
            $this->activate($slug, $version, $extractedRoot, $sourceRegistryUrl, $manifest->entryClass);
        } catch (\Throwable $e) {
            $this->removeDirIfExists($stagingContainer);
            $this->lastError = "Les migrations du bundle ont échoué : " . $e->getMessage();
            return BundleInstallResult::failure($this->lastError);
        }
        $this->removeDirIfExists($stagingContainer);

        $progress(100, 'Bundle installé.');
        return BundleInstallResult::installed($manifest);
    }

    private function resolveDownloadUrl(string $repositoryUrl, string $version): ?string
    {
        if ($this->releaseFetcher !== null) {
            return ($this->releaseFetcher)($repositoryUrl, $version);
        }

        if (!preg_match('#^https://github\.com/([^/]+)/([^/]+?)/?$#', rtrim($repositoryUrl), $m)) {
            $this->lastError = "URL de dépôt non reconnue : {$repositoryUrl} (seuls les dépôts GitHub sont supportés pour l'instant).";
            return null;
        }

        $apiUrl = "https://api.github.com/repos/{$m[1]}/{$m[2]}/releases/tags/v{$version}";
        $body = $this->http->get($apiUrl, [
            'User-Agent: Kintai-BundleInstaller/1.0',
            'Accept: application/vnd.github+json',
        ], self::DOWNLOAD_TIMEOUT_SECONDS);

        if ($body === null) {
            $this->lastError = "La release v{$version} de {$repositoryUrl} est introuvable ou inaccessible.";
            return null;
        }

        $data = json_decode($body, true);
        if (!is_array($data) || !isset($data['zipball_url']) || !is_string($data['zipball_url'])) {
            $this->lastError = "Réponse GitHub inattendue pour la release v{$version}.";
            return null;
        }

        return $data['zipball_url'];
    }

    private function download(string $url, string $destination): bool
    {
        $dir = dirname($destination);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if ($this->zipDownloader !== null) {
            return ($this->zipDownloader)($url, $destination);
        }

        return $this->http->downloadToFile($url, $destination, ['User-Agent: Kintai-BundleInstaller/1.0'], self::DOWNLOAD_TIMEOUT_SECONDS);
    }

    /**
     * @return array{0: BundleManifest, 1: string}|null [manifeste, chemin du dossier extrait]
     */
    private function verifyAndExtract(string $zipPath, string $expectedSlug, string $expectedVersion, string $stagingContainer): ?array
    {
        $extractedRoot = $this->extractZip($zipPath, $stagingContainer . '/extract');
        if ($extractedRoot === null) {
            $this->lastError = "L'archive ne contient pas exactement un dossier racine.";
            return null;
        }

        $manifestPath = $extractedRoot . '/bundle.json';
        if (!is_file($manifestPath)) {
            $this->lastError = 'bundle.json introuvable dans l\'archive.';
            return null;
        }

        $data = json_decode((string) file_get_contents($manifestPath), true);
        $errors = BundleManifest::validationErrors($data, $expectedSlug, false);
        $manifest = $errors === [] ? BundleManifest::fromArray($data) : null;
        if ($manifest === null) {
            $this->lastError = 'bundle.json invalide : ' . ($errors !== [] ? implode(' ', $errors) : 'incomplet.');
            return null;
        }

        if ($manifest->slug !== $expectedSlug) {
            $this->lastError = "Le slug du manifeste ({$manifest->slug}) ne correspond pas au bundle attendu ({$expectedSlug}).";
            return null;
        }

        // Contrat de bundle (kintai_contract), ou à défaut ancien intervalle kintai_core.min/max.
        $incompatible = $manifest->incompatibilityReason($this->updateService->getCurrentVersion());
        if ($incompatible !== null) {
            $this->lastError = $incompatible;
            return null;
        }

        $entryFile = $manifest->classFilePath($extractedRoot, $manifest->entryClass);
        if ($entryFile === null || !is_file($entryFile)) {
            $this->lastError = "Classe {$manifest->entryClass} introuvable dans l'archive.";
            return null;
        }

        return [$manifest, $extractedRoot];
    }

    private function activate(string $slug, string $version, string $extractedRoot, ?string $sourceRegistryUrl, string $entryClass): void
    {
        $firstInstall = $this->installedBundles->find($slug) === null;
        $finalDir = $this->bundlesDir() . "/{$slug}/{$version}";
        $this->removeDirIfExists($finalDir);

        $parent = dirname($finalDir);
        if (!is_dir($parent)) {
            mkdir($parent, 0755, true);
        }

        rename($extractedRoot, $finalDir);

        $migrationsPath = $finalDir . '/database/migrations';
        if ($this->migrationRunner !== null && is_dir($migrationsPath)) {
            try {
                $this->migrationRunner->runPendingFor($slug, $migrationsPath);
            } catch (\Throwable $e) {
                // Schéma incomplet : on n'active pas un bundle dont les migrations ont échoué.
                $this->removeDirIfExists($finalDir);
                throw $e;
            }
        }

        $this->manifestStore->setActiveVersion($slug, $version);
        $this->installedBundles->upsert($slug, $version, $sourceRegistryUrl);

        if ($firstInstall && $this->permissionGranter !== null) {
            try {
                // Classes du bundle chargeables dès cette requête (l'autoloader du démarrage ne le connaît pas encore).
                (new InstalledBundleAutoloader($this->manifestStore, $this->bundlesDir()))->register();
                $granted = $this->permissionGranter->grantDeclaredBy($entryClass);
                Log::info('bundle_manager_permissions_granted', ['slug' => $slug, 'keys' => $granted]);
            } catch (\Throwable $e) {
                // Le bundle est installé ; seuls les droits restent à accorder à la main dans Rôles.
                Log::warning('bundle_manager_permissions_failed', ['slug' => $slug, 'error' => $e->getMessage()]);
            }
        }

        Log::info('bundle_installed', ['slug' => $slug, 'version' => $version]);
    }

    private function extractZip(string $zipPath, string $extractDir): ?string
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return null;
        }
        if (!is_dir($extractDir)) {
            mkdir($extractDir, 0755, true);
        }
        $zip->extractTo($extractDir);
        $zip->close();

        $dirs = glob($extractDir . '/*', GLOB_ONLYDIR);
        if ($dirs === false || count($dirs) !== 1) {
            return null;
        }

        return $dirs[0];
    }

    private function removeDirIfExists(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getRealPath()) : unlink($item->getRealPath());
        }
        rmdir($dir);
    }

    private function bundlesDir(): string
    {
        return $this->bundlesDir ?? storage_path('bundles');
    }
}
