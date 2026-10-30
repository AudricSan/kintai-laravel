<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Routing\BoundParam;
use App\Routing\RouteParamBinder;

/** Résolveur sans alias : le segment est l'identifiant nu (URL des tests, sans base de données). */
final class BareRouteBinder implements RouteParamBinder
{
    public function resolve(string $segment): ?BoundParam
    {
        return ctype_digit($segment) ? new BoundParam((int) $segment, true) : null;
    }

    public function segmentFor(int $id): string
    {
        return (string) $id;
    }

    public function reset(): void {}
}