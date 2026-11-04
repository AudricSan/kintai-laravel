<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Un compte vient d'être anonymisé : les bundles (agenda, annuaire…) nettoient leurs propres données.
 */
class UserAnonymized
{
    use Dispatchable;

    public function __construct(public readonly int $userId) {}
}
