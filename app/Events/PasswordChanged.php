<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Un utilisateur vient de changer son propre mot de passe.
 */
class PasswordChanged
{
    use Dispatchable;

    public function __construct(public readonly int $userId) {}
}
