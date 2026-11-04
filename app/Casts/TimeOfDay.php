<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Colonne `time` lue et écrite sous la forme "H:i" (les secondes sont ignorées).
 *
 * @implements CastsAttributes<string|null, string|null>
 */
class TimeOfDay implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : substr((string) $value, 0, 5);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : substr((string) $value, 0, 5);
    }
}
