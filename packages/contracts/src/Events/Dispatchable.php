<?php

namespace Kintai\Contracts\Events;

use Illuminate\Support\Facades\Event;

/**
 * Permet d'émettre un événement par `MonEvenement::dispatch(...$arguments)`, comme le trait de Laravel, sans dépendre
 * de `illuminate/foundation` (le contrat ne requiert que les composants Illuminate dont il se sert).
 */
trait Dispatchable
{
    /**
     * @return array<int, mixed>|null
     */
    public static function dispatch(mixed ...$arguments): ?array
    {
        return Event::dispatch(new static(...$arguments));
    }

    /**
     * @return array<int, mixed>|null
     */
    public static function dispatchIf(bool $boolean, mixed ...$arguments): ?array
    {
        return $boolean ? Event::dispatch(new static(...$arguments)) : null;
    }

    /**
     * @return array<int, mixed>|null
     */
    public static function dispatchUnless(bool $boolean, mixed ...$arguments): ?array
    {
        return $boolean ? null : Event::dispatch(new static(...$arguments));
    }
}
