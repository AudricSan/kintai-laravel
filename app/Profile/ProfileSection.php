<?php

namespace App\Profile;

use App\Models\User;
use Closure;

/**
 * Une page de la zone « réglages personnels ».
 */
final class ProfileSection
{
    /**
     * @param  (Closure(User): bool)|null  $visible
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $route,
        public readonly int $order,
        private readonly ?Closure $visible,
    ) {}

    public function isVisibleTo(User $user): bool
    {
        return $this->visible === null || (bool) ($this->visible)($user);
    }
}
