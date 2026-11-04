<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * La fiche d'un membre d'un magasin vient d'être modifiée.
 */
class StoreMemberUpdated
{
    use Dispatchable;

    public function __construct(
        public readonly int $storeId,
        public readonly int $userId,
    ) {}
}
