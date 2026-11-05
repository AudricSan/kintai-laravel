<?php

namespace Kintai\Contracts\Events;

/**
 * Un magasin vient d'être créé.
 */
final class StoreCreated
{
    use Dispatchable;

    public function __construct(public readonly int $storeId) {}
}
