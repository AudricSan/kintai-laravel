<?php

namespace Kintai\Contracts\Events;

/**
 * Les informations d'un employé viennent d'être modifiées.
 */
final class EmployeeUpdated
{
    use Dispatchable;

    public function __construct(public readonly int $userId) {}
}
