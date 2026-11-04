<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Les informations d'un employé viennent d'être modifiées.
 */
class EmployeeUpdated
{
    use Dispatchable;

    public function __construct(public readonly int $userId) {}
}
