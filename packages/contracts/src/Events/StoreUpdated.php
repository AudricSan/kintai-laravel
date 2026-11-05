<?php

namespace Kintai\Contracts\Events;

/**
 * Les réglages d'un magasin viennent d'être modifiés.
 */
final class StoreUpdated
{
    use Dispatchable;

    public function __construct(public readonly int $storeId) {}
}
