<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Un employé vient d'être retiré d'un magasin.
 */
class StoreMemberRemoved
{
    use Dispatchable;

    public function __construct(
        public readonly int $storeId,
        public readonly int $userId,
    ) {}
}
