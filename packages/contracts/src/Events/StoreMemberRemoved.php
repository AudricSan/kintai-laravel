<?php

namespace Kintai\Contracts\Events;

/**
 * Un employé vient d'être retiré d'un magasin.
 */
final class StoreMemberRemoved
{
    use Dispatchable;

    public function __construct(
        public readonly int $storeId,
        public readonly int $userId,
    ) {}
}
