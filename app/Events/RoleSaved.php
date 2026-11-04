<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Un rôle vient d'être créé (`created`) ou modifié.
 */
class RoleSaved
{
    use Dispatchable;

    public function __construct(public readonly int $roleId, public readonly bool $created) {}
}
