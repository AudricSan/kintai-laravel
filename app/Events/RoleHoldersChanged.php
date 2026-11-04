<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Les titulaires d'un rôle viennent de changer. `userId`, `storeId` et `added` précisent le changement
 * quand il est connu (`storeId` null = portée globale).
 */
class RoleHoldersChanged
{
    use Dispatchable;

    public function __construct(
        public readonly int $roleId,
        public readonly ?int $userId = null,
        public readonly ?int $storeId = null,
        public readonly bool $added = true,
    ) {}
}
