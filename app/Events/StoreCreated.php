<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Un magasin vient d'être créé.
 */
class StoreCreated
{
    use Dispatchable;

    public function __construct(public readonly int $storeId) {}
}
