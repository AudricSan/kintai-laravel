<?php

namespace Kintai\Contracts\Events;

/**
 * Un magasin vient d'être supprimé.
 */
final class StoreDeleted
{
    use Dispatchable;

    public function __construct(public readonly int $storeId) {}
}
