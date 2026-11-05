<?php

namespace Kintai\Contracts\Events;

/**
 * Un utilisateur vient de changer son propre mot de passe.
 */
final class PasswordChanged
{
    use Dispatchable;

    public function __construct(public readonly int $userId) {}
}
