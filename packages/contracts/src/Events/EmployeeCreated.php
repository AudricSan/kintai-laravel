<?php

namespace Kintai\Contracts\Events;

/**
 * Un employé vient d'être créé (`source` : form, import…). Le journal d'activité et les bundles l'écoutent.
 */
final class EmployeeCreated
{
    use Dispatchable;

    public function __construct(public readonly int $userId, public readonly string $source) {}
}
