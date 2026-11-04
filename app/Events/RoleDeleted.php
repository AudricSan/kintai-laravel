<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Un rôle vient d'être supprimé ; son nom est gardé car la ligne n'existe plus.
 */
class RoleDeleted
{
    use Dispatchable;

    public function __construct(public readonly int $roleId, public readonly string $name) {}
}
