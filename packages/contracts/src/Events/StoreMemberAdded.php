<?php

namespace Kintai\Contracts\Events;

/**
 * Un employé vient d'être ajouté à un magasin.
 */
final class StoreMemberAdded
{
    use Dispatchable;

    public function __construct(
        public readonly int $storeId,
        public readonly int $userId,
    ) {}
}
