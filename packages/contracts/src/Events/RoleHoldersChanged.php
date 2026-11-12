<?php

namespace Kintai\Contracts\Events;

/**
 * Les titulaires d'un rôle viennent de changer. `userId`, `storeId` et `added` précisent le changement
 * quand il est connu (`storeId` null = portée globale).
 */
final class RoleHoldersChanged
{
    use Dispatchable;

    public function __construct(
        public readonly int $roleId,
        public readonly ?int $userId = null,
        public readonly ?int $storeId = null,
        public readonly bool $added = true,
    ) {}
}
