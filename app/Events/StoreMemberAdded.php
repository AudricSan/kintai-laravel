<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Un employé vient d'être ajouté à un magasin.
 */
class StoreMemberAdded
{
    use Dispatchable;

    public function __construct(
        public readonly int $storeId,
        public readonly int $userId,
    ) {}
}
