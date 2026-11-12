<?php

namespace Kintai\Contracts\Events;

/**
 * Un utilisateur vient de modifier son propre profil.
 */
final class ProfileUpdated
{
    use Dispatchable;

    public function __construct(public readonly int $userId) {}
}
