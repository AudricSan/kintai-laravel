<?php

declare(strict_types=1);

namespace kintai\Core\Services\BundleRegistry;

use kintai\Core\Repositories\BundleRegistryRepositoryInterface;

/**
 * Agrège les listings de tous les registries configurés en un seul
 * catalogue pour l'écran d'installation (à venir dans une prochaine
 * itération, une fois BundleInstallerService disponible).
 */
final class BundleCatalogService
{
    public function __construct(
        private readonly BundleRegistryRepositoryInterface $registries,
        private readonly BundleRegistryClient $client,
    ) {
    }

    /**
     * @return BundleCatalogEntry[]
     */
    public function listAvailableBundles(): array
    {
        $catalog = [];
        foreach ($this->registries->all() as $registry) {
            $listing = $this->client->fetchListing($registry['url']);
            if ($listing === null) {
                continue;
            }
            foreach ($listing->bundles as $entry) {
                $catalog[] = new BundleCatalogEntry($entry, $registry['name'], $registry['url']);
            }
        }
        return $catalog;
    }

    /**
     * Commit épinglé par un registry pour cette version d'un bundle, retrouvé CÔTÉ SERVEUR à partir du
     * registry (jamais lu depuis la requête : le formulaire ne doit pas pouvoir choisir ce qu'on vérifie).
     *
     * `$registryUrl` désigne le registry d'où vient l'installation ; sans lui, tous les registries configurés
     * sont consultés. Trois issues :
     *  - un commit épinglé : l'archive devra y correspondre ;
     *  - aucun commit (registry qui n'épingle pas, ou version absente) : installation comme avant ;
     *  - une erreur : le registry visé est injoignable et on ne peut pas savoir s'il épingle cette version.
     *    Il ne faut PAS installer dans ce cas : sinon, empêcher la lecture du registry suffirait à
     *    désactiver la vérification.
     *
     * @return array{commit: ?string, error: ?string}
     */
    public function resolvePin(?string $registryUrl, string $slug, string $version): array
    {
        $targets = [];
        foreach ($this->registries->all() as $registry) {
            if ($registryUrl === null || $registryUrl === '' || $registry['url'] === $registryUrl) {
                $targets[] = $registry['url'];
            }
        }
        // URL de registry inconnue de cette instance : on retombe sur tous les registries configurés.
        if ($targets === [] && $registryUrl !== null && $registryUrl !== '') {
            foreach ($this->registries->all() as $registry) {
                $targets[] = $registry['url'];
            }
        }

        $unreachable = false;
        foreach ($targets as $url) {
            $listing = $this->client->fetchListing($url);
            if ($listing === null) {
                $unreachable = true;
                continue;
            }
            foreach ($listing->bundles as $entry) {
                if ($entry->slug === $slug) {
                    $commit = $entry->commitFor($version);
                    if ($commit !== null) {
                        return ['commit' => $commit, 'error' => null];
                    }
                }
            }
        }

        if ($unreachable) {
            return [
                'commit' => null,
                'error'  => "Impossible de lire le registry pour vérifier l'empreinte de cette version : installation refusée par précaution.",
            ];
        }

        return ['commit' => null, 'error' => null];
    }
    public function isOfficial(string $slug): bool
    {
        $path = BASE_PATH . '/config/official-bundles.php';
        $officialSlugs = file_exists($path) ? (require $path) : [];
        return in_array($slug, $officialSlugs, true);
    }
}
