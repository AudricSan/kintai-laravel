<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Les réglages d'un magasin viennent d'être modifiés.
 */
class StoreUpdated
{
    use Dispatchable;

    public function __construct(public readonly int $storeId) {}
}
