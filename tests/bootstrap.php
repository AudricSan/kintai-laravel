<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

// Les tests démarrent la vraie application (configuration chargée), sans lire le .env de l'instance et avec une
// session en mémoire.
putenv('SESSION_DRIVER=array');
$_ENV['APP_KEY'] = $_SERVER['APP_KEY'] = 'base64:' . base64_encode(str_repeat('k', 32));
putenv('APP_KEY=' . $_ENV['APP_KEY']);
$_ENV['SESSION_DRIVER'] = $_SERVER['SESSION_DRIVER'] = 'array';

$app = $GLOBALS["kintai_test_app"] = require dirname(__DIR__) . "/bootstrap/standalone.php";
$app->loadEnvironmentFrom('.env.testing');
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Les bundles du dépôt (bundles/<slug>) : leurs migrations suivent celles du Cœur et leurs vues ont leur espace de noms,
// sans dépendre de l'ordre des tests ni d'un bundle activé dans la base de l'instance.
foreach (glob(dirname(__DIR__) . '/bundles/*', GLOB_ONLYDIR) ?: [] as $bundleDirectory) {
    if (is_dir($bundleDirectory . '/database/migrations')) {
        $app->make('migrator')->path($bundleDirectory . '/database/migrations');
    }
    if (is_dir($bundleDirectory . '/Views')) {
        $app->make('view')->addNamespace(basename($bundleDirectory), $bundleDirectory . '/Views');
    }
}

// Aides des tests : routeur de Laravel vierge, que ResetAuthSessionFingerprint remplace par celui de l'application
// avant chaque test.
$GLOBALS['kintai_test_router'] = $app->make('router');

function kintai_test_router(): Illuminate\Routing\Router
{
    $app    = $GLOBALS['kintai_test_app'];
    $router = new Illuminate\Routing\Router($app->make('events'), $app);
    $app->instance('router', $router);
    Illuminate\Support\Facades\Route::clearResolvedInstance('router');

    return $router;
}

/** Enregistreur de routes à la manière des bundles ($router->get('/chemin', [Classe::class, 'm'], name: '…')). */
function kintai_test_registrar(): Tests\Support\RouteRegistrar
{
    return new Tests\Support\RouteRegistrar(kintai_test_router());
}

/** Les vraies routes du cœur (routes/web.php et routes/api.php) dans un routeur vierge. */
function kintai_test_load_routes(): Illuminate\Routing\Router
{
    $router = kintai_test_router();
    require dirname(__DIR__) . '/routes/web.php';
    require dirname(__DIR__) . '/routes/api.php';

    return $router;
}

// Aide des tests : en-têtes d'une réponse sous forme de tableau « Nom => valeur ».
function kintai_test_headers(object $response): array
{
    $headers = [];
    foreach ($response->headers->allPreserveCaseWithoutCookies() as $name => $values) {
        $headers[$name] = implode(', ', $values);
    }

    return $headers;
}

// Aide des tests : donne à une requête construite à la main les paramètres qu'une route Laravel lui aurait donnés.
Illuminate\Http\Request::macro('setRouteParams', function (array $params): void {
    /** @var Illuminate\Http\Request $this */
    $route = new Illuminate\Routing\Route(['GET'], '/', []);
    $route->bind($this);
    foreach ($params as $name => $value) {
        $route->setParameter($name, $value);
    }
    $this->setRouteResolver(static fn () => $route);
});

// Aide des tests : base neuve dont la table bundle_registries contient ces registries (name, url, is_official).
function kintai_test_registries(array $rows): void
{
    kintai_test_database();
    kintai_test_migrate();
    App\Models\BundleRegistry::query()->delete();
    foreach ($rows as $row) {
        App\Models\BundleRegistry::create(['name' => $row['name'], 'url' => $row['url'], 'is_official' => $row['is_official'] ?? false]);
    }
}

// Aide des tests : base neuve pour le routage lisible ; les employés (id => numéro d'employé, ou null) sont créés.
function kintai_test_route_slugs(array $employeeCodes = []): void
{
    kintai_test_database();
    kintai_test_migrate();
    foreach ($employeeCodes as $id => $code) {
        kintai_test_user((int) $id);
        App\Models\User::where('id', $id)->update(['employee_code' => $code]);
    }
}
// Aide des tests : base neuve où ces employés ont ces disponibilités hebdomadaires dans le magasin 1.
function kintai_test_availabilities(array $userIds, array $rows): void
{
    kintai_test_database();
    kintai_test_migrate();
    Illuminate\Support\Facades\DB::table('stores')->insertOrIgnore(['id' => 1, 'name' => 'Magasin', 'code' => 'M1']);
    foreach ($userIds as $userId) {
        kintai_test_user((int) $userId);
        foreach ($rows as $row) {
            App\Models\Availability::create($row + ['user_id' => $userId, 'store_id' => 1, 'start_time' => '00:00', 'end_time' => '23:59']);
        }
    }
}
// Aide des tests : exécute une commande Artisan (ses services viennent du conteneur de test) ; renvoie [code de sortie, sortie].
function kintai_test_command(Illuminate\Console\Command $command, array $input = []): array
{
    $command->setLaravel($GLOBALS['kintai_test_app']);
    $output = new Symfony\Component\Console\Output\BufferedOutput();
    $code   = $command->run(new Symfony\Component\Console\Input\ArrayInput($input), $output);

    return [$code, trim($output->fetch())];
}

// Aide des tests : remplace le journal de Laravel par un RecordingLog qui retient les entrées (Log::info, AuditLogger…).
// Une base neuve (kintai_test_database) remet le vrai journal.
function kintai_test_log(): Tests\Support\RecordingLog
{
    $log = new Tests\Support\RecordingLog();
    $GLOBALS['kintai_test_app']->instance('log', $log);
    Illuminate\Support\Facades\Log::clearResolvedInstance('log');

    return $log;
}

// Aide des tests : base SQLite en mémoire neuve, installée comme connexion par défaut de l'application (DB::, Schema::,
// Eloquent, migrateur). Renvoie la Capsule posée sur cette connexion.
function kintai_test_database(): App\Database\LaravelCapsule
{
    $app = $GLOBALS['kintai_test_app'];
    $app->forgetInstance('log');
    Illuminate\Support\Facades\Log::clearResolvedInstance('log');
    config(['database.connections.testing' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
    $app['db']->purge('testing');
    $app['db']->setDefaultConnection('testing');
    config(['database.default' => 'testing']);

    // Les modèles Eloquent reviennent sur le gestionnaire de l'application (un test peut avoir posé une autre Capsule).
    Illuminate\Database\Eloquent\Model::setConnectionResolver($app['db']);

    $capsule = new App\Database\LaravelCapsule($app);
    $capsule->setAsGlobal();

    return $capsule;
}

// Aide des tests : `php artisan migrate` puis `php artisan db:seed` sur la base de kintai_test_database().
function kintai_test_migrate(): void
{
    // Certains tests vident $_SERVER ; la console de Symfony lit PHP_SELF.
    $_SERVER['PHP_SELF'] ??= 'artisan';
    $kernel = $GLOBALS['kintai_test_app']->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->call('migrate', ['--force' => true]);
    $kernel->call('db:seed', ['--force' => true]);
}

// Aide des tests : traducteur de Laravel adossé à des textes donnés (langue => clé => texte), installé comme
// « translator » de l'application (__() et trans() le lisent). Remis par kintai_test_translator_reset().
function kintai_test_translator(array $catalog = [], string $locale = 'en'): Illuminate\Translation\Translator
{
    $loader = new Illuminate\Translation\ArrayLoader();
    foreach ($catalog as $code => $lines) {
        $loader->addMessages($code, '*', $lines);
    }

    return kintai_test_install_translator(new Illuminate\Translation\Translator($loader, $locale));
}

// Aide des tests : traducteur sur les vrais fichiers lang/ (JSON et validation.php).
function kintai_test_real_translator(string $locale = 'en', bool $install = true): Illuminate\Translation\Translator
{
    $translator = new Illuminate\Translation\Translator(
        new Illuminate\Translation\FileLoader(new Illuminate\Filesystem\Filesystem(), dirname(__DIR__) . '/lang'),
        $locale,
    );
    $translator->setFallback($locale);

    return $install ? kintai_test_install_translator($translator) : $translator;
}

function kintai_test_install_translator(Illuminate\Translation\Translator $translator): Illuminate\Translation\Translator
{
    $app = $GLOBALS['kintai_test_app'];
    $GLOBALS['kintai_test_translator_original'] ??= $app->make('translator');
    $app->instance('translator', $translator);
    Illuminate\Support\Facades\Lang::clearResolvedInstance('translator');

    return $translator;
}

function kintai_test_translator_reset(): void
{
    $GLOBALS['kintai_test_app']->instance('translator', $GLOBALS['kintai_test_translator_original']);
    Illuminate\Support\Facades\Lang::clearResolvedInstance('translator');
}

// Par défaut les tests n'ont aucun texte : __() rend la clé. Un test qui veut des textes installe le sien.
$GLOBALS['kintai_test_translator_original'] = new Illuminate\Translation\Translator(new Illuminate\Translation\ArrayLoader(), 'en');
kintai_test_translator_reset();


// Aide des tests : moteur de vues de Laravel sur un autre dossier de vues (vues d'essai, copie…), avec les directives et
// les helpers de l'application.
/**
 * Messages flashés en session (success, danger, warning) par le code testé, retirés de la session : [type => texte].
 *
 * @return array<string, string>
 */
function kintai_test_flash(): array
{
    $messages = [];
    foreach (['success', 'danger', 'warning'] as $type) {
        if (session()->has($type)) {
            $messages[$type] = (string) session($type);
        }
    }
    session()->flush();

    return $messages;
}

/**
 * Mailer de Laravel dont le transport transmet chaque message à un rappel (voir Tests\Support\RecordingTransport) :
 * function (array $to, string $subject, string $html, array $headers): bool.
 */
function kintai_test_mailer(Closure $onSend): Illuminate\Mail\Mailer
{
    return new Illuminate\Mail\Mailer('test', app('view'), new Tests\Support\RecordingTransport($onSend));
}

/** Donne un corps JSON à une requête déjà construite (Request::capture() des tests). */
function kintai_test_set_json(Illuminate\Http\Request $request, array $json): void
{
    (new ReflectionProperty(Symfony\Component\HttpFoundation\Request::class, 'content'))->setValue($request, json_encode($json));
    $request->headers->set('Content-Type', 'application/json');
    (new ReflectionProperty(Illuminate\Http\Request::class, 'json'))->setValue($request, null);
}

/** Route qui répond à cette méthode et ce chemin dans le routeur courant des tests. */
function kintai_test_match(string $method, string $path): Illuminate\Routing\Route
{
    return app('router')->getRoutes()->match(Illuminate\Http\Request::create($path, $method));
}

/** Utilisateur minimal dans la base de test (après kintai_test_migrate()), pour les lignes qui le référencent. */
function kintai_test_user(int $id = 1): int
{
    $now = date('Y-m-d H:i:s');
    Illuminate\Support\Facades\DB::table('users')->insertOrIgnore([
        'id' => $id, 'first_name' => 'Test', 'last_name' => 'User' . $id, 'display_name' => 'Test User' . $id,
        'email' => 'user' . $id . '@test.local', 'password_hash' => 'x', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
    ]);

    return $id;
}

function kintai_test_views(string $path, bool $withLayout = false): Illuminate\View\Factory
{
    $app    = $GLOBALS['kintai_test_app'];
    // Mises en page réduites à leur contenu (tests/Fixtures/views/layout) devant les vraies : un test de page vérifie la
    // page, pas la navigation qui l'entoure.
    $finder = new Illuminate\View\FileViewFinder(new Illuminate\Filesystem\Filesystem(), $withLayout ? [$path] : [__DIR__ . '/Fixtures/views', $path]);
    $views  = new Illuminate\View\Factory($app['view.engine.resolver'], $finder, $app['events']);
    $views->setContainer($app);
    $views->share('app', $app);

    return $views;
}