<?php

declare(strict_types=1);

// Noms du contrat de bundle (kintai\Contract\…), déclarés avant tout chargement de bundle : voir app/Contract/aliases.php.
require_once __DIR__ . '/../Contract/aliases.php';

if (!function_exists('kintai_installed_via_database')) {
    /**
     * Fallback à storage/installed.lock : ce marqueur vit hors Git (gitignored) et peut
     * disparaître pour des raisons externes à l'application (jamais reproduit via un simple
     * git checkout — voir CHANGELOG) ; le prendre pour argent comptant force alors un
     * réinstall destructeur alors que la base de données est en réalité intacte. Interroge
     * directement la DB (config présente, connexion OK, au moins un utilisateur) avant de
     * conclure que l'app n'est pas installée. Utilise du PDO natif plutôt qu'Eloquent : ce
     * garde-fou tourne avant le boot complet du framework, dans public/index.php et
     * public/install.php.
     */
    function kintai_installed_via_database(string $basePath): bool
    {
        $env = [];
        foreach (['.env.local', '.env.ovh', '.env'] as $file) {
            if (is_file($basePath . '/' . $file)) {
                $env = \Dotenv\Dotenv::parse((string) file_get_contents($basePath . '/' . $file));
                break;
            }
        }

        try {
            $driver = $env['DB_CONNECTION'] ?? 'sqlite';

            if ($driver === 'sqlite') {
                $sqlitePath = $env['DB_DATABASE'] ?? $basePath . '/storage/app/database.sqlite';
                if (!file_exists($sqlitePath)) {
                    return false;
                }
                $pdo = new PDO('sqlite:' . $sqlitePath);
            } elseif ($driver === 'mysql') {
                $dsn = sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                    $env['DB_HOST'] ?? '127.0.0.1',
                    (int) ($env['DB_PORT'] ?? 3306),
                    $env['DB_DATABASE'] ?? 'kintai',
                    $env['DB_CHARSET'] ?? 'utf8mb4'
                );
                $pdo = new PDO($dsn, $env['DB_USERNAME'] ?? 'root', $env['DB_PASSWORD'] ?? '');
            } else {
                return false;
            }

            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $count = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
            return $count > 0;
        } catch (\Throwable) {
            return false;
        }
    }
}

if (!function_exists('bundle_enabled')) {
    /**
     * Indique si un bundle est réellement actif après le boot — découvert (sur
     * le disque ou installé dynamiquement) ET activé (BundleManager::isActive(),
     * pas seulement FeatureManager::isEnabled(), qui ne reflète que le réglage
     * stocké). Les deux peuvent diverger quand un bundle reste marqué "activé"
     * dans les réglages existants d'une instance mais disparaît du disque —
     * exactement ce qui arrive à un bundle qu'on vient d'extraire du monorepo
     * tant que personne ne l'a réinstallé depuis /admin/bundles/market. Utiliser
     * l'ancien comportement (FeatureManager seul) ferait générer par les vues
     * (nav, sidebar) des liens vers des routes qui n'existent plus, plantant la
     * page entière. "Fail open" (true) si le service n'est pas encore prêt,
     * comme __() — l'accès réel reste de toute façon protégé par les routes.
     */
    function bundle_enabled(string $slug): bool
    {
        try {
            $container = \Illuminate\Container\Container::getInstance();
            if ($container->has(\App\BundleManager::class)) {
                return $container->make(\App\BundleManager::class)->isActive($slug);
            }
        } catch (\Throwable $e) {
            // En cas d'erreur avant que le service soit prêt
        }
        return true;
    }
}

if (!function_exists('bundle_asset')) {
    /**
     * URL publique d'un asset statique (CSS/JS) déclaré par le bundle actif
     * $slug via Bundle::loadAssetsFrom(), pour un <link>/<script> dans une
     * vue. $path est relatif au dossier d'assets du bundle (ex. 'css/notebook.css').
     * Retourne null (jamais d'exception) si le bundle est inactif ou n'a
     * pas déclaré d'assets — chaque vue appelante doit donc faire
     * `if ($css = bundle_asset(...)): <link ...>`.
     */
    function bundle_asset(string $slug, string $path): ?string
    {
        try {
            $container = \Illuminate\Container\Container::getInstance();
            if (!$container->has(\App\BundleManager::class)) {
                return null;
            }
            $manager = $container->make(\App\BundleManager::class);
            if ($manager->assetsPathFor($slug) === null) {
                return null;
            }
            $version = $manager->versionOf($slug) ?? '0';
            return base_url() . '/bundle-assets/' . rawurlencode($slug) . '/' . ltrim($path, '/') . '?v=' . urlencode($version);
        } catch (\Throwable) {
            return null;
        }
    }
}

if (!function_exists('bundle_asset_path')) {
    /**
     * Chemin filesystem absolu d'un asset statique déclaré par le bundle
     * actif $slug via Bundle::loadAssetsFrom() — pour les vues qui ont
     * besoin du contenu du fichier directement (ex. file_get_contents()
     * dans un export PDF), plutôt que d'une URL. Mêmes règles que
     * bundle_asset() : null si le bundle est inactif ou sans assets.
     */
    function bundle_asset_path(string $slug, string $path): ?string
    {
        try {
            $container = \Illuminate\Container\Container::getInstance();
            if (!$container->has(\App\BundleManager::class)) {
                return null;
            }
            $root = $container->make(\App\BundleManager::class)->assetsPathFor($slug);
        } catch (\Throwable) {
            return null;
        }
        return $root === null ? null : rtrim($root, '/\\') . '/' . ltrim($path, '/');
    }
}

if (!function_exists('feat_bundle')) {
    /**
     * Pour un slug de feature par-store (ex: 'timeoff', 'swaps'), indique si le
     * bundle Core dont il dépend est activé au niveau de l'instance. Retourne
     * true si le slug ne correspond à aucun bundle connu (fonctionnalité Core,
     * toujours active) ou si $slug est null. À combiner avec le toggle par
     * store (ex: $feat() dans les vues) qui ne couvre pas ce niveau.
     */
    function feat_bundle(?string $slug): bool
    {
        // Fonctionnalités du Core, des bundles actifs (déclarées ou historiques) : voir Extension\StoreFeatures.
        return $slug === null || \App\Extension\StoreFeatures::current()->isAvailable($slug);
    }
}

if (!function_exists('hash_token')) {
    function hash_token(string $raw): string
    {
        return hash('sha256', $raw);
    }
}

if (!function_exists('back_url')) {
    /**
     * Destination d'un bouton "Retour"/"Annuler" : une même page pouvant être atteinte
     * depuis plusieurs écrans (liste filtrée, tableau de bord, recherche...), on ne code
     * jamais une destination fixe. On préfère le Referer envoyé par le navigateur — il
     * pointe exactement vers l'écran d'où l'utilisateur vient, filtres/pagination/magasin
     * sélectionné compris puisqu'ils font partie de son URL — et on ne retombe sur
     * $fallback que si le Referer est absent, hors origine (navigateur qui ne l'envoie
     * pas, accès direct par URL/favori) ou identique à la page courante (rechargement).
     */
    function back_url(string $fallback): string
    {
        try {
            $request = app('request');
        } catch (\Throwable) {
            return $fallback;
        }

        $referer = $request->header('Referer');
        $host = $request->server->get('HTTP_HOST');
        if (!$referer || !$host) {
            return $fallback;
        }

        $ownOrigin = ($request->isSecure() ? 'https://' : 'http://') . $host;
        if ($referer !== $ownOrigin && !str_starts_with($referer, $ownOrigin . '/')) {
            return $fallback;
        }

        $refererPath = parse_url($referer, PHP_URL_PATH) ?: '/';
        $scriptBase = base_url();
        if ($scriptBase !== '' && str_starts_with($refererPath, $scriptBase)) {
            $refererPath = substr($refererPath, strlen($scriptBase));
        }
        $refererPath = '/' . trim($refererPath, '/');
        if ($refererPath === $request->slashPath()) {
            return $fallback;
        }

        return $referer;
    }
}

if (!function_exists('render_markdown')) {
    /**
     * Rend du Markdown en HTML (Parsedown, safe mode) pour l'affichage de
     * contenu provenant d'une source externe (ex : notes de version GitHub) —
     * contrairement à WikiContentService::render(), qui désactive le safe mode
     * car les pages du wiki sont des fichiers locaux de confiance.
     */
    function render_markdown(string $markdown): string
    {
        $parsedown = new \Parsedown();
        $parsedown->setSafeMode(true);

        return $parsedown->text($markdown);
    }
}

if (!function_exists('asset_version')) {
    /**
     * Suffixe de cache-busting (?v=...) pour app.css/app.js/notifications.js,
     * et valeur reprise telle quelle par la route /sw.js (PwaController) pour
     * sa constante CACHE. Calculé automatiquement à partir du mtime le plus
     * récent sous public/assets/css et public/assets/js — plus rien à bumper
     * à la main : toute modification d'un fichier CSS/JS change la valeur au
     * prochain appel. Apache ne pose aucun Cache-Control sur les fichiers
     * statiques servis directement (voir public/.htaccess), donc sans URL
     * versionnée le cache HTTP heuristique du navigateur pouvait continuer à
     * servir une ancienne version indéfiniment.
     */
    function asset_version(): string
    {
        static $version = null;
        if ($version !== null) {
            return $version;
        }

        $latest = 0;
        foreach (['/public/assets/css', '/public/assets/js'] as $dir) {
            $path = BASE_PATH . $dir;
            if (!is_dir($path)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                $mtime = $file->getMTime();
                if ($mtime > $latest) {
                    $latest = $mtime;
                }
            }
        }

        return $version = substr(md5((string) $latest), 0, 8);
    }
}

if (!function_exists('mascot_path')) {
    /**
     * Chemin relatif (depuis public/assets/img/) d'une pose de mascotte pour
     * le contexte donné (ex. 'login', 'http-error/404', 'footer-fox-2'),
     * résolu via MascotResolver (mémoïsé par requête, replie sur kitsune si
     * l'asset de la mascotte active n'existe pas). Fail-open sur kitsune si
     * le service n'est pas encore prêt, comme bundle_enabled()/__().
     */
    function mascot_path(string $context): string
    {
        try {
            $container = \Illuminate\Container\Container::getInstance();
            if ($container->has(\App\Services\MascotResolver::class)) {
                return $container->make(\App\Services\MascotResolver::class)->path($context);
            }
        } catch (\Throwable $e) {
            // En cas d'erreur avant que le service soit prêt
        }
        return "mascot/kitsune/{$context}.png";
    }
}

if (!function_exists('mascot_active')) {
    /** Mascotte active pour cette requête ('kitsune' ou 'tanuki') — voir mascot_path(). */
    function mascot_active(): string
    {
        try {
            $container = \Illuminate\Container\Container::getInstance();
            if ($container->has(\App\Services\MascotResolver::class)) {
                return $container->make(\App\Services\MascotResolver::class)->active();
            }
        } catch (\Throwable $e) {
            // En cas d'erreur avant que le service soit prêt
        }
        return 'kitsune';
    }
}

if (!function_exists('kintai_load_env_file')) {
    /**
     * Chargeur .env, partagé par le front controller (public/index.php) et les scripts en ligne de commande
     * (scripts/cron.php) — nécessaire sur OVH (FastCGI), où le SetEnv du .htaccess n'atteint pas PHP.
     * Priorité : .env.local (dev) → .env.ovh (production OVH) → .env (générique) ; un seul fichier chargé. Les
     * variables déjà présentes dans l'environnement système ne sont jamais écrasées.
     */
    function kintai_load_env_file(string $basePath): void
    {
        foreach (['.env.local', '.env.ovh', '.env'] as $name) {
            $file = $basePath . '/' . $name;
            if (!file_exists($file)) {
                continue;
            }
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                    continue;
                }
                [$k, $v] = explode('=', $line, 2);
                $k = trim($k);
                $v = trim($v);
                if (strlen($v) >= 2 && $v[0] === $v[-1] && ($v[0] === '"' || $v[0] === "'")) {
                    $v = substr($v, 1, -1);
                }
                if ($k !== '' && !isset($_ENV[$k])) {
                    putenv("$k=$v");
                    $_ENV[$k]    = $v;
                    $_SERVER[$k] = $v;
                }
            }
            break; // un seul fichier chargé
        }
    }
}

if (!function_exists('kintai_normalize_script_name')) {
    /**
     * Ramène SCRIPT_NAME à l'URL réellement vue par le visiteur quand le .htaccess racine a réécrit la
     * requête en interne vers public/ (domaine ou dossier pointant sur la racine du dépôt, sans passer par
     * public/ comme DocumentRoot). Apache renseigne alors SCRIPT_NAME avec « /…/public/index.php » alors que
     * l'URL demandée ne contient pas « /public » : sans correction, base_url() et le routeur en déduiraient
     * que l'application vit sous /public et chaque lien généré le contiendrait.
     *
     * Sans effet quand public/ est le DocumentRoot, et quand « /public » figure dans l'URL demandée (accès
     * direct par /Kintai/public/, comme avant l'introduction de la réécriture).
     * À appeler une fois, en tête du point d'entrée (index.php, install.php).
     */
    function kintai_normalize_script_name(): void
    {
        $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $dir        = rtrim(dirname($scriptName), '/');
        if (!str_ends_with($dir, '/public')) {
            return;
        }

        $path = rawurldecode((string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/'));
        if ($path === $dir || str_starts_with($path, $dir . '/')) {
            return;
        }

        $root = substr($dir, 0, -strlen('/public'));
        foreach (['SCRIPT_NAME', 'PHP_SELF'] as $key) {
            $value = str_replace('\\', '/', (string) ($_SERVER[$key] ?? ''));
            if (str_starts_with($value, $dir . '/')) {
                $_SERVER[$key] = $root . substr($value, strlen($dir));
            }
        }
    }
}

if (!function_exists('base_url')) {
    /**
     * Calcule la base URL à partir de SCRIPT_NAME.
     * Exemples :
     *   DocumentRoot = public/         → SCRIPT_NAME=/index.php           → ''
     *   DocumentRoot = htdocs/         → SCRIPT_NAME=/Kintai/public/index.php → /Kintai/public
     */
    function base_url(): string
    {
        $sn   = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php');
        $base = rtrim(str_replace('\\', '/', dirname($sn)), '/');
        return ($base === '.' || $base === '/') ? '' : $base;
    }

    /**
     * Génère une URL à partir d'un nom de route et de paramètres.
     * Utilise le Router pour résoudre le pattern de la route nommée.
     */
    function route_url(string $name, array $params = []): string
    {
        return base_url() . \App\Http\Routing\Routes::url($name, $params);
    }
}

if (!function_exists('safe_redirect_path')) {
    /**
     * Destination de redirection fournie par la requête (redirect_to, return_to…), acceptée seulement si c'est un
     * chemin interne : commence par « / », sans « // » ni « \ » en tête (les navigateurs lisent « /\site.example »
     * comme « //site.example », une autre origine), sans antislash ni caractère de contrôle, sans schéma ni hôte.
     * Sinon $fallback. Contrôle commun à tous les formulaires qui renvoient l'utilisateur d'où il vient.
     */
    function safe_redirect_path(?string $path, string $fallback): string
    {
        $path = trim((string) $path);
        if ($path === ''
            || $path[0] !== '/'
            || str_starts_with($path, '//')
            || str_contains($path, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $path) === 1
        ) {
            return $fallback;
        }

        $parts = parse_url($path);
        if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) {
            return $fallback;
        }

        return $path;
    }
}

if (!function_exists('route_segment')) {
    /**
     * Segment d'URL lisible (encodé) d'une entité adressée par un paramètre de route typé : alias d'un magasin
     * (所沢東町店, tokorozawa-higashicho), numéro d'un employé (057, ou id-42). Pour les URLs construites à la main ;
     * route_url() fait la même conversion toute seule. Retombe sur l'identifiant si le routage typé est
     * indisponible (tests, base pas encore migrée).
     */
    function route_segment(string $type, int|string $id): string
    {
        try {
            $binder = \App\Http\Routing\Routes::binder($type);
            $segment = $binder !== null ? $binder->segmentFor((int) $id) : (string) $id;
        } catch (\Throwable) {
            $segment = (string) $id;
        }

        return rawurlencode($segment);
    }

    function store_segment(int|string $storeId): string
    {
        return route_segment('store', $storeId);
    }

    function employee_segment(int|string $userId): string
    {
        return route_segment('employee', $userId);
    }
}

if (!function_exists('notification_type_catalog')) {
    /**
     * Libellé + icône par type de notification, seule source pour le dropdown
     * (_topbar.php) et la liste complète (notifications/index.php) — ces deux vues
     * maintenaient chacune leur propre copie de ce tableau, et un type ajouté d'un
     * côté sans l'autre s'affichait avec sa clé technique brute au lieu d'un libellé
     * traduit (ex. notebook_entry_created, initialement oublié dans l'un des deux).
     *
     * Types du Core plus ceux déclarés par les bundles actifs (NotificationTypeRegistry, libellé « notif_{type} »).
     * Les notifications déjà reçues d'un bundle désactivé restent affichées : libellé par convention, icône par défaut.
     */
    function notification_type_catalog(): array
    {
        $types = [];
        try {
            $container = \Illuminate\Container\Container::getInstance();
            if ($container->has(\App\Extension\ExtensionRegistry::class)) {
                $types = $container->make(\App\Services\NotificationTypeRegistry::class)->all();
            }
        } catch (\Throwable) {
        }
        $catalog = [];
        foreach ($types as $type => $definition) {
            $catalog[$type] = ['label' => __('notif_' . $type), 'icon' => $definition->icon];
        }
        return $catalog;
    }

    /**
     * Identifiant d'un jour de planning (« 2026-08-02 » → 20260802) pour les notifications dont l'objet n'existe plus
     * (shift supprimé) : le reference_id désigne alors le jour, reference_type « shift_day ».
     */
    function shift_day_reference(string $date): int
    {
        return (int) preg_replace('/\D/', '', $date);
    }

    /**
     * Le shift est-il visible des employés ? Faux pour un shift en brouillon (magasin à publication du planning, date
     * après la dernière publication, voir SchedulePublication). Hors application (pas de conteneur) : vrai.
     *
     * @param array<string, mixed> $shift
     */
    function shift_is_published(array $shift): bool
    {
        return \App\Services\SchedulePublication::current()?->isPublished($shift) ?? true;
    }

    /** Libellé d'un type : catalogue, sinon traduction « notif_{type} » si elle existe (bundle inactif), sinon le type. */
    function notification_type_label(string $type): string
    {
        return notification_type_catalog()[$type]['label'] ?? translation_or($type, 'notif_' . $type);
    }

    function notification_type_icon(string $type): string
    {
        return notification_type_catalog()[$type]['icon'] ?? '•';
    }

    /**
     * Texte d'une notification dans la langue courante : traduction de body_key avec ses paramètres, sinon
     * l'instantané body (lignes antérieures à body_key, ou clé d'un bundle désactivé depuis).
     */
    function notification_body(array $notification): string
    {
        $key = (string) ($notification['body_key'] ?? '');
        if ($key !== '') {
            $params     = is_array($notification['params'] ?? null) ? $notification['params'] : [];
            $translated = __($key, $params);
            if ($translated !== $key) {
                return $translated;
            }
        }
        return (string) ($notification['body'] ?? '');
    }

    /** Libellé du badge de la cloche, identique côté serveur et dans notifications.js : 1…99, puis « 99+ ». */
    function notification_badge_label(int $count): string
    {
        return $count > 99 ? '99+' : (string) $count;
    }
}

if (!function_exists('translation_or')) {
    /**
     * Traduction de $key si elle existe (Core ou bundles, voir JsonTranslationRepository), sinon $fallback. Sert aux
     * libellés trouvés par convention de nommage (notif_{type}, audit_{action}) : un bundle les fournit dans ses
     * fichiers lang/ sans que le Core ait à les connaître.
     */
    function translation_or(string $fallback, string $key): string
    {
        $translated = __($key);
        return $translated !== $key ? $translated : $fallback;
    }
}

if (!function_exists('instance_locale')) {
    /**
     * Langue de l'instance (APP_LOCALE, voir InstanceLocale), ou l'anglais si l'application n'est pas démarrée.
     */
    function instance_locale(): string
    {
        try {
            return \Illuminate\Container\Container::getInstance()->make(\App\Services\InstanceLocale::class)->code();
        } catch (\Throwable) {
            $configured = (string) env(\App\Services\InstanceLocale::ENV_KEY, '');
            return $configured !== '' ? $configured : 'en';
        }
    }
}

if (!function_exists('user_locale')) {
    /**
     * Langue d'un utilisateur pour ce qui lui est écrit hors de sa session (notifications, e-mails, iCal) : celle
     * de son profil si elle est active, sinon celle de l'instance.
     */
    function user_locale(?array $user): string
    {
        try {
            return \Illuminate\Container\Container::getInstance()->make(\App\Services\InstanceLocale::class)->forUser($user);
        } catch (\Throwable) {
            $language = (string) ($user['language'] ?? '');
            return $language !== '' ? $language : instance_locale();
        }
    }
}

if (!function_exists('audit_action_label')) {
    /**
     * Libellé d'une action du journal d'activité : liste connue de la vue, sinon traduction « audit_{action} » (points
     * remplacés par des tirets bas, ex. timeoff.approved → audit_timeoff_approved), sinon l'action brute.
     *
     * @param array<string, string> $known
     */
    function audit_action_label(string $action, array $known = []): string
    {
        return $known[$action] ?? translation_or($action, 'audit_' . str_replace(['.', '-'], '_', $action));
    }
}

if (!function_exists('safe_color')) {
    /**
     * Couleur utilisable dans un attribut style="" ou data-* : la valeur si c'est un hexadécimal
     * valide, sinon le repli. Ceinture et bretelles en plus de la validation à la saisie
     * (ColorRule) pour les données déjà en base ou écrites par un bundle.
     */
    function safe_color(mixed $value, string $fallback = '#6366f1'): string
    {
        return \App\Validation\ColorRule::safe($value, $fallback);
    }
}

if (!function_exists('month_label')) {
    /**
     * Mois et année traduits (« Octobre 2026 », « October 2026 », « 2026年10月 ») pour une date « Y-m » ou « Y-m-d ».
     * Remplace DateTime::format('F Y'), toujours en anglais. Les noms de mois sont des clés du Core (January…December) :
     * ils ne venaient jusqu'ici que du bundle salary-report, et s'affichaient en anglais sans lui.
     */
    function month_label(string $date): string
    {
        $ts = strtotime(strlen($date) === 7 ? $date . '-01' : $date);
        if ($ts === false) {
            return $date;
        }
        $names = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October',
            'November', 'December'];
        $month = __($names[(int) date('n', $ts) - 1]);
        $label = __('month_year', ['month' => $month, 'year' => date('Y', $ts)]);
        // Traductions absentes (ligne de commande, tests) : __() rend la clé telle quelle.
        return $label === 'month_year' ? $month . ' ' . date('Y', $ts) : $label;
    }
}

if (!function_exists('date_label')) {
    /**
     * Date complète traduite (« Jeudi 15 Octobre 2026 », « Thursday 15 October 2026 », « 2026年10月15日（木曜日） »).
     * Remplace DateTime::format('l d F Y'), toujours en anglais.
     */
    function date_label(string $date): string
    {
        $ts = strtotime($date);
        if ($ts === false) {
            return $date;
        }
        $weekday = __(strtolower(date('l', $ts)));
        $month   = month_label(date('Y-m', $ts));
        $vars    = ['weekday' => $weekday, 'day' => date('j', $ts), 'month_year' => $month];
        $label   = __('date_long', $vars);
        return $label === 'date_long' ? $weekday . ' ' . date('j', $ts) . ' ' . $month : $label;
    }
}
