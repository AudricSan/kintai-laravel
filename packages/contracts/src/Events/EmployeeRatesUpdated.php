<?php

namespace Kintai\Contracts\Events;

/**
 * Les taux horaires personnels d'un employé viennent d'être modifiés.
 */
final class EmployeeRatesUpdated
{
    use Dispatchable;

    public function __construct(public readonly int $userId) {}
}
