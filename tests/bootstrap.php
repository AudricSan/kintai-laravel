<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

// Les tests démarrent la vraie application (configuration chargée), sans lire le .env de l'instance et avec une
// session en mémoire.
putenv('SESSION_DRIVER=array');
$_ENV['SESSION_DRIVER'] = $_SERVER['SESSION_DRIVER'] = 'array';

$app = $GLOBALS["kintai_test_app"] = require dirname(__DIR__) . "/bootstrap/standalone.php";
$app->loadEnvironmentFrom('.env.testing');
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

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

// Aide des tests : base SQLite en mémoire neuve, installée comme connexion par défaut de l'application (DB::, Schema::,
// Eloquent, migrateur). Renvoie la Capsule posée sur cette connexion.
function kintai_test_database(): App\Database\LaravelCapsule
{
    $app = $GLOBALS['kintai_test_app'];
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
