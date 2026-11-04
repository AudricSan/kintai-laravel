<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Un employé vient d'être créé (`source` : form, import…). Le journal d'activité et les bundles l'écoutent.
 */
class EmployeeCreated
{
    use Dispatchable;

    public function __construct(public readonly int $userId, public readonly string $source) {}
}
