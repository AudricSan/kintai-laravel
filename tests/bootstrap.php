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

// Aide des tests : en-têtes d'une réponse sous forme de tableau « Nom => valeur ».
function kintai_test_headers(object $response): array
{
    if ($response instanceof kintai\Core\Response) {
        return $response->headers();
    }

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
