<?php

declare(strict_types=1);

namespace kintai\Core\BundleContract;

use kintai\Core\Application;

/**
 * Point de contact stable pour tout auteur de bundle, interne ou tiers.
 *
 * Tout ce qui vit sous kintai\Core\BundleContract suit son propre
 * versionnage sémantique, annoncé dans une section dédiée du CHANGELOG :
 * ajouter une méthode optionnelle est mineur, changer une signature ou en
 * supprimer une est majeur et documenté. Le reste de kintai\Core\* peut
 * évoluer entre deux versions mineures sans préavis — un bundle ne doit
 * dépendre que de ce qui est déclaré ici (plus des interfaces de
 * src/Core/Repositories/*Interface.php, déjà un contrat stable par
 * construction).
 */
abstract class Bundle
{
    protected Application $app;
    protected string $path;
    private ?string $assetsPath = null;

    public function __construct(Application $app)
    {
        $this->app = $app;
        $this->path = $this->resolvePath();
    }

    /**
     * Identifiant unique du bundle (slug). Doit être identique au champ
     * "slug" de bundle.json pour un bundle installé dynamiquement.
     */
    abstract public function getName(): string;

    /**
     * Enregistre services, routes ou vues.
     */
    abstract public function register(): void;

    /**
     * Version du bundle. Un bundle legacy (monorepo, sans bundle.json) peut
     * laisser la valeur par défaut ; un bundle installé dynamiquement doit
     * la faire correspondre au champ "version" de son bundle.json.
     */
    public function getVersion(): string
    {
        return '0.0.0';
    }

    /**
     * Libellé lisible affiché dans /admin/bundles. À surcharger pour traduire.
     */
    public function getLabel(): string
    {
        return ucwords(str_replace(['-', '_'], ' ', $this->getName()));
    }

    /**
     * Description lisible affichée dans /admin/bundles. À surcharger pour traduire.
     */
    public function getDescription(): string
    {
        return '';
    }

    /**
     * Point d'extension « navigation » (contrat 1.1) : entrées que le bundle ajoute à la barre du haut et, si elles
     * y sont éligibles, à la barre mobile. Le Core les rend lui-même ; il ne cite plus aucun bundle par son nom.
     *
     * @return list<\kintai\Contract\Extension\NavItem>
     */
    public function navigation(): array
    {
        return [];
    }

    /**
     * Point d'extension « permissions » (contrat 1.2) : catégories de permissions apportées par le bundle, sous la
     * forme catégorie => actions (ex. ['timeoff' => ['view', 'create', 'approve']]). Elles apparaissent dans la page
     * des rôles tant que le bundle est actif ; les clés « catégorie.action » restent valides en base s'il est
     * désactivé. Une catégorie du Core (employees, shifts, stores, payroll) ne peut pas être redéfinie.
     *
     * @return array<string, list<string>>
     */
    public function permissions(): array
    {
        return [];
    }

    /**
     * Point d'extension « fonctionnalités par magasin » (contrat 1.3) : cases proposées sur la fiche magasin tant
     * que le bundle est actif, enregistrées dans store_features sous leur clé.
     *
     * @return list<\kintai\Contract\Extension\StoreFeature>
     */
    public function storeFeatures(): array
    {
        return [];
    }

    /**
     * Point d'extension « widgets de tableau de bord » (contrat 1.4) : widgets proposés sur le tableau de bord gérant
     * ou employé tant que le bundle est actif. Dès qu'un bundle en déclare, le Core n'affiche plus ses widgets
     * historiques pour ce bundle.
     *
     * @return list<\kintai\Contract\Extension\DashboardWidget>
     */
    public function dashboardWidgets(): array
    {
        return [];
    }

    /**
     * Point d'extension « fenêtres de page » (contrat 1.27) : fenêtres affichées par-dessus les pages d'un utilisateur
     * connecté (une question à poser, une validation à faire), rendues par le Core dans un garde, une seule à la fois.
     * Voir kintai\Contract\Extension\PageOverlay.
     *
     * @return list<\kintai\Contract\Extension\PageOverlay>
     */
    public function pageOverlays(): array
    {
        return [];
    }

    /**
     * Point d'extension « variables de vue » (contrat 1.5) : variables partagées avec toutes les vues des pages
     * authentifiées, sous la forme nom => fn(array $utilisateur): mixed (ex. un compteur affiché en badge dans la
     * navigation, voir NavItem::$badge). Chaque fonction est appelée à chaque page : elle doit rester légère.
     *
     * @return array<string, \Closure(array): mixed>
     */
    public function viewVariables(): array
    {
        return [];
    }

    /**
     * Point d'extension « actions » (contrat 1.6) : liens ajoutés dans les emplacements des pages du Core (menu
     * d'une ligne du personnel, fiche employé, rapport employés, planning employé — voir Action::SLOTS).
     *
     * @return list<\kintai\Contract\Extension\Action>
     */
    public function actions(): array
    {
        return [];
    }

    /**
     * Point d'extension « types de notification » (contrat 1.7) : types des notifications créées par le bundle.
     * Le libellé est la traduction « notif_{type} » (fichiers lang/ du bundle). Deux formats :
     *  - simple : type => icône (un emoji) ; catégorie « system », push activé, e-mail désactivé ;
     *  - enrichi : type => ['icon' => …, 'category' => planning|requests|messages|reports|system,
     *    'channels' => ['push' => bool, 'email' => bool] (valeurs par défaut des préférences),
     *    'reference_type' => type d'objet désigné par l'identifiant passé à notify()] — voir NotificationType.
     *
     * @return array<string, string|array<string, mixed>>
     */
    public function notificationTypes(): array
    {
        return [];
    }

    /**
     * Point d'extension « tâches planifiées » (contrat 1.8) : classes implémentant kintai\Contract\Cron\CronJob,
     * résolues par le conteneur et lancées par un planificateur externe via /cron/run/{nom}?token=… (jeton créé par
     * l'Owner). Le nom (getName()) doit être unique ; ceux du Core (backup, log-purge, license-check, notification-purge, system-notifications, calendar-sync) sont réservés.
     *
     * @return list<class-string>
     */
    public function cronJobs(): array
    {
        return [];
    }

    /**
     * Fournisseurs de demandes en attente (contrat 1.9) : le Core les lit pour la page « Demandes », le compteur du
     * tableau de bord et les alertes, sans connaître les tables du bundle.
     *
     * @return list<\kintai\Contract\Provider\PendingRequestsProvider>
     */
    public function pendingRequestsProviders(): array
    {
        return [];
    }

    /**
     * Fournisseurs d'absences (contrat 1.10) : le Core les lit pour le planning (conflits, affichage), l'export iCal
     * et les statistiques, sans connaître les tables du bundle.
     *
     * @return list<\kintai\Contract\Provider\AbsenceProvider>
     */
    public function absenceProviders(): array
    {
        return [];
    }

    /**
     * Fournisseurs de pointages (contrat 1.11) : le Core les lit pour le tableau de bord (pointages en cours ou
     * oubliés, retards), sans connaître les tables du bundle.
     *
     * @return list<\kintai\Contract\Provider\AttendanceProvider>
     */
    public function attendanceProviders(): array
    {
        return [];
    }

    /**
     * Point d'extension « sections du profil » (contrat 1.12) : sections ajoutées au formulaire « Mes informations »
     * du profil (vue du bundle + cases de la table users qu'elle enregistre).
     *
     * @return list<\kintai\Contract\Extension\ProfileSection>
     */
    public function profileSections(): array
    {
        return [];
    }

    /**
     * Point d'extension « jeux de données exportables » (contrat 1.30) : données du bundle proposées dans la page
     * « Exports » en JSON, CSV et classeur Excel, pour une personne, un groupe ou un magasin. Le Core vérifie les droits
     * (ExportDataset::$permission, par magasin) et ne passe au fournisseur qu'un périmètre déjà autorisé.
     *
     * @return list<\kintai\Contract\Extension\ExportDataset>
     */
    public function exportDatasets(): array
    {
        return [];
    }

    /**
     * Point d'extension « événements » (contrat 1.13) : auditeurs des événements que le Core annonce (classes de
     * kintai\Contract\Event, par exemple EmployeeCreated), un par événement. Le Core appelle chaque auditeur avec
     * l'objet de l'événement ; une exception est journalisée sans interrompre l'action du Core.
     *
     * @return array<class-string, callable(object): void>
     */
    public function eventListeners(): array
    {
        return [];
    }

    /**
     * Fournisseurs de ventes journalières (contrat 1.14) : le Core les lit pour la rentabilité des magasins, et les
     * bundles par kintai\Contract\Services\Sales, sans connaître les tables du bundle.
     *
     * @return list<\kintai\Contract\Provider\SalesProvider>
     */
    public function salesProviders(): array
    {
        return [];
    }

    /**
     * Point d'extension « sections du formulaire de shift » (contrat 1.17) : champs ajoutés au formulaire de shift et
     * boutons de sa barre d'actions (vues du bundle + colonnes de shifts qu'elles enregistrent).
     *
     * @return list<\kintai\Contract\Extension\ShiftFormSection>
     */
    public function shiftFormSections(): array
    {
        return [];
    }

    /**
     * Fournisseurs d'échanges de shifts (contrat 1.18) : le Core les lit pour ses statistiques (nombre d'échanges d'un
     * employé), sans connaître les tables du bundle.
     *
     * @return list<\kintai\Contract\Provider\SwapProvider>
     */
    public function swapProviders(): array
    {
        return [];
    }

    /**
     * Point d'extension « cartes de statistiques » (contrat 1.19) : cartes du haut du tableau de bord employé (libellé,
     * icône, valeur calculée pour l'employé connecté).
     *
     * @return list<\kintai\Contract\Extension\DashboardStat>
     */
    public function dashboardStats(): array
    {
        return [];
    }

    /**
     * Boot du bundle, une fois tous les bundles enregistrés.
     */
    public function boot(): void
    {
        // Optionnel
    }

    /**
     * Chemin racine du bundle sur le disque.
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Racine du bundle sur le disque — le dossier contenant bundle.json,
     * routes.php, Views/, lang/, PAS forcément celui de la classe elle-même.
     *
     * Pour un bundle legacy (monorepo, src/Bundles/{Name}/{Name}Bundle.php),
     * les deux coïncident. Pour un bundle installé dynamiquement, la classe
     * d'entrée vit sous src/ (BundleManifest::SOURCE_ROOT) alors que
     * routes.php/Views/lang vivent à la racine du bundle, un niveau
     * au-dessus — d'où la détection explicite ci-dessous plutôt qu'un simple
     * dirname() du fichier de la classe.
     */
    protected function resolvePath(): string
    {
        $reflector = new \ReflectionClass(static::class);
        $dir = dirname($reflector->getFileName());

        if (basename($dir) === 'src' && is_file(dirname($dir) . '/bundle.json')) {
            return dirname($dir);
        }

        return $dir;
    }

    /**
     * Aide à l'enregistrement des routes depuis un fichier.
     */
    protected function loadRoutesFrom(string $path): void
    {
        $router = $this->app->router();
        $container = $this->app->container();
        require $path;
    }

    /**
     * Aide à l'enregistrement des vues sous un namespace.
     */
    protected function loadViewsFrom(string $path, string $namespace): void
    {
        $viewRenderer = $this->app->container()->make(\kintai\UI\ViewRenderer::class);
        $viewRenderer->addNamespace($namespace, $path);
    }

    /**
     * Aide à l'enregistrement des assets statiques (CSS/JS/images) du
     * bundle. $relativeDir est résolu par rapport à getPath() (la racine
     * du bundle, pas celle de la classe) — convention attendue : 'public'.
     * Lu par BundleManager::assetsPathFor(), qui alimente à son tour
     * BundleAssetController (route GET /bundle-assets/{slug}/{path*}) et
     * les helpers bundle_asset()/bundle_asset_path(). Un bundle qui
     * n'appelle jamais cette méthode n'expose simplement aucun asset —
     * pas d'erreur, juste un 404 sur toute requête vers son namespace.
     */
    protected function loadAssetsFrom(string $relativeDir): void
    {
        $this->assetsPath = rtrim($this->getPath(), '/\\') . '/' . trim($relativeDir, '/\\');
    }

    /**
     * @internal Lu par BundleManager::assetsPathFor() — jamais destiné à
     * être appelé directement par le code d'un bundle.
     */
    public function getAssetsPath(): ?string
    {
        return $this->assetsPath;
    }
}
