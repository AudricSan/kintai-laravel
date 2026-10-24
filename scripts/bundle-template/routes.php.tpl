<?php

declare(strict_types=1);

use kintai\Bundles\Installed\{{Studly}}\Controllers\Web\{{Studly}}Controller;

/** @var kintai\Contract\Http\Router $router */
/** @var kintai\Contract\Container $container */

// =============================================================================
// {{Label}} — Routes Web. `permission:` rattache la route au RBAC (déclaré par
// {{Studly}}Bundle::permissions()) ; sans lui, la route est refusée.
// =============================================================================

$router->group('/{{slug}}', function ($r) {
    $r->get('', [{{Studly}}Controller::class, 'index'], name: '{{prefixDot}}.index', permission: '{{prefix}}.view');
});
