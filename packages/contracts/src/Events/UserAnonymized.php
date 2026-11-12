<?php

namespace Kintai\Contracts\Events;

/**
 * Un compte vient d'être anonymisé : les bundles (agenda, annuaire…) nettoient leurs propres données.
 */
final class UserAnonymized
{
    use Dispatchable;

    public function __construct(public readonly int $userId) {}
}
