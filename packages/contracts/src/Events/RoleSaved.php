<?php

namespace Kintai\Contracts\Events;

/**
 * Un rôle vient d'être créé (`created`) ou modifié.
 */
final class RoleSaved
{
    use Dispatchable;

    public function __construct(public readonly int $roleId, public readonly bool $created) {}
}
