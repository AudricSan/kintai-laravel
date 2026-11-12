<?php

namespace Kintai\Contracts\Events;

/**
 * Le mot de passe d'un employé vient d'être réinitialisé ou redéfini par un gérant.
 */
final class EmployeePasswordReset
{
    use Dispatchable;

    public function __construct(public readonly int $userId) {}
}
