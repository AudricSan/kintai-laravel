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
function kintai_test_registrar(): kintai\Tests\Support\RouteRegistrar
{
    return new kintai\Tests\Support\RouteRegistrar(kintai_test_router());
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
