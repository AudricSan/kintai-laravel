<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Un utilisateur vient de modifier son propre profil.
 */
class ProfileUpdated
{
    use Dispatchable;

    public function __construct(public readonly int $userId) {}
}
