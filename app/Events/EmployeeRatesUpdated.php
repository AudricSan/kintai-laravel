<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Les taux horaires personnels d'un employé viennent d'être modifiés.
 */
class EmployeeRatesUpdated
{
    use Dispatchable;

    public function __construct(public readonly int $userId) {}
}
