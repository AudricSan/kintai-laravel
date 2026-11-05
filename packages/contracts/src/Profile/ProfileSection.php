<?php

namespace Kintai\Contracts\Profile;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Une page de la zone « réglages personnels ».
 */
final class ProfileSection
{
    /**
     * @param  (Closure(Authenticatable): bool)|null  $visible
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $route,
        public readonly int $order,
        private readonly ?Closure $visible,
    ) {}

    public function isVisibleTo(Authenticatable $user): bool
    {
        return $this->visible === null || (bool) ($this->visible)($user);
    }
}
