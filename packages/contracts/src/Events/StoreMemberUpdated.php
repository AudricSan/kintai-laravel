<?php

namespace Kintai\Contracts\Events;

/**
 * La fiche d'un membre d'un magasin vient d'être modifiée.
 */
final class StoreMemberUpdated
{
    use Dispatchable;

    public function __construct(
        public readonly int $storeId,
        public readonly int $userId,
    ) {}
}
