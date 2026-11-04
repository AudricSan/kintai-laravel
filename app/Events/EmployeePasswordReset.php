<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Le mot de passe d'un employé vient d'être réinitialisé ou redéfini par un gérant.
 */
class EmployeePasswordReset
{
    use Dispatchable;

    public function __construct(public readonly int $userId) {}
}
