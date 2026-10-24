<?php

declare(strict_types=1);

namespace kintai\Domain\Eloquent\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use kintai\Core\Validation\ColorRule;

/**
 * Couleur hexadécimale lue depuis la base : une valeur qui n'est pas #RGB / #RRGGBB (donnée ancienne ou
 * écrite sans passer par la validation) est remplacée à la lecture, pour qu'elle n'atteigne jamais un
 * attribut style="" ou data-* des vues. Paramètre du cast = couleur de repli (« color:#3B82F6 ») ;
 * sans paramètre, une valeur invalide devient null (couleur facultative, comme celle des rôles).
 *
 * @implements CastsAttributes<?string, ?string>
 */
final class HexColor implements CastsAttributes
{
    public function __construct(private readonly ?string $fallback = null) {}

    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return $this->fallback;
        }

        return ColorRule::isValid($value) ? (string) $value : $this->fallback;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
