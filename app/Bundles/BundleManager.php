<?php

namespace App\Bundles;

use App\License\LicenseState;
use App\Settings\AppSettings;
use Illuminate\Foundation\Application;
use InvalidArgumentException;
use Kintai\Contracts\Bundle\BundleActivation;
use Kintai\Contracts\Bundle\BundleServiceProvider;
use Kintai\Contracts\Contract;
use LogicException;
use Throwable;

/**
 * Bundles installés et activés. Le Core ne connaît jamais un bundle par son nom : il découvre les
 * `BundleServiceProvider` enregistrés par Laravel (auto-découverte `extra.laravel.providers` des packages) et garde dans
 * le réglage `bundles.enabled` (liste JSON de slugs) ceux que l'Owner a activés. Par défaut aucun bundle n'est activé à
 * l'installation : l'Owner choisit.
 *
 * Un bundle n'est « activé » que s'il est installé, présent dans la liste et compatible avec la version du contrat
 * (`Kintai\Contracts\Contract::VERSION`) : un bundle devenu incompatible après une mise à jour est donc ignoré sans
 * erreur. Un bundle hors des droits de la licence (`LicenseState::allowsBundle()`) n'est jamais activé : c'est le point
 * central par où passent le menu, les routes, les widgets et les commandes planifiées. Désactiver un bundle ne supprime aucune donnée (ses migrations restent chargées).
 *
 * Les bundles se branchent au démarrage de l'application : un changement d'état s'applique à partir de la requête
 * suivante (si les routes sont en cache, le fichier du cache est supprimé pour qu'elles soient recalculées).
 */
class BundleManager implements BundleActivation
{
    public const SETTING = 'bundles.enabled';

    public function __construct(
        private readonly Application $app,
        private readonly AppSettings $settings,
        private readonly LicenseState $license,
    ) {}

    /**
     * Bundles installés, par slug, triés par nom.
     *
     * @return array<string, BundleServiceProvider>
     */
    public function installed(): array
    {
        $bundles = [];

        foreach ($this->app->getProviders(BundleServiceProvider::class) as $provider) {
            $slug = $provider->slug();

            if (isset($bundles[$slug]) && $provider::class !== $bundles[$slug]::class) {
                throw new LogicException("Two bundles declare the slug [{$slug}]: ".$bundles[$slug]::class.' and '.$provider::class.'.');
            }

            // Le dernier fournisseur enregistré d'une même classe fait foi.
            $bundles[$slug] = $provider;
        }

        uasort($bundles, fn (BundleServiceProvider $a, BundleServiceProvider $b): int => [mb_strtolower($a->name()), $a->slug()] <=> [mb_strtolower($b->name()), $b->slug()]);

        return $bundles;
    }

    public function find(string $slug): ?BundleServiceProvider
    {
        return $this->installed()[$slug] ?? null;
    }

    /**
     * Le bundle est-il compatible avec la version du contrat installée ?
     */
    public function isCompatible(BundleServiceProvider $bundle): bool
    {
        try {
            return Contract::satisfies($bundle->requiredContract());
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Slugs cochés par l'Owner (installés ou non : l'état d'un bundle retiré puis réinstallé est conservé).
     *
     * @return list<string>
     */
    public function selected(): array
    {
        $decoded = json_decode($this->settings->string(self::SETTING), true);

        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_unique(array_filter($decoded, fn (mixed $slug): bool => is_string($slug) && $slug !== '')));
    }

    /**
     * Les droits de la licence couvrent-ils ce bundle ? Les données d'un bundle hors licence restent intactes.
     */
    public function isLicensed(string $slug): bool
    {
        return $this->license->allowsBundle($slug);
    }

    /**
     * Installé, activé par l'Owner, compatible avec le contrat et couvert par la licence.
     */
    public function isEnabled(string $slug): bool
    {
        $bundle = $this->find($slug);

        return $bundle !== null && in_array($slug, $this->selected(), true) && $this->isCompatible($bundle) && $this->isLicensed($slug);
    }

    /**
     * Slugs des bundles réellement activés, triés.
     *
     * @return list<string>
     */
    public function enabledSlugs(): array
    {
        $slugs = array_values(array_filter(array_keys($this->installed()), fn (string $slug): bool => $this->isEnabled($slug)));
        sort($slugs);

        return $slugs;
    }

    /**
     * Active un bundle (sans effet s'il l'est déjà).
     *
     * @return bool Vrai si l'état a changé.
     *
     * @throws BundleException Bundle inconnu, incompatible avec le contrat ou hors des droits de la licence.
     */
    public function enable(string $slug): bool
    {
        $bundle = $this->find($slug) ?? throw BundleException::unknown($slug);

        if (! $this->isCompatible($bundle)) {
            throw BundleException::incompatible($bundle->name(), $bundle->requiredContract(), Contract::VERSION);
        }

        if (! $this->isLicensed($slug)) {
            throw BundleException::notLicensed($bundle->name());
        }

        $selected = $this->selected();

        if (in_array($slug, $selected, true)) {
            return false;
        }

        $this->store([...$selected, $slug]);

        return true;
    }

    /**
     * Désactive un bundle (sans effet s'il ne l'est pas). Aucune donnée n'est supprimée.
     *
     * @return bool Vrai si l'état a changé.
     *
     * @throws BundleException Bundle inconnu.
     */
    public function disable(string $slug): bool
    {
        $this->find($slug) ?? throw BundleException::unknown($slug);

        $selected = $this->selected();

        if (! in_array($slug, $selected, true)) {
            return false;
        }

        $this->store(array_values(array_diff($selected, [$slug])));

        return true;
    }

    /**
     * @param  list<string>  $slugs
     */
    private function store(array $slugs): void
    {
        sort($slugs);
        $this->settings->set(self::SETTING, (string) json_encode($slugs));
        $this->forgetCachedRoutes();
    }

    /**
     * Les routes d'un bundle n'existent que s'il est activé : un cache de routes calculé avant le changement serait faux.
     */
    private function forgetCachedRoutes(): void
    {
        try {
            if ($this->app->routesAreCached()) {
                @unlink($this->app->getCachedRoutesPath());
            }
        } catch (Throwable) {
            // Le cache sera recalculé au prochain `route:clear` ; le changement d'état, lui, est déjà enregistré.
        }
    }
}
