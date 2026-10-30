<?php

declare(strict_types=1);

namespace App\Validation;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Règle unique pour toute couleur saisie par un utilisateur (types de shift, comptes, rôles).
 *
 * Seul le format hexadécimal #RGB / #RRGGBB est accepté : une valeur libre finissait telle quelle dans
 * un attribut style="" (injection CSS stockée, par exemple « red;position:fixed;… »).
 */
final class ColorRule implements ValidationRule
{
    /** Règle de Laravel : Validator::make($data, ["color" => [new ColorRule()]]). */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!self::isValid($value)) {
            $fail('val_field_invalid')->translate(['field' => $attribute]);
        }
    }

    public static function isValid(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})\z/', $value) === 1;
    }

    /** Valeur sûre à injecter dans un attribut : la couleur si elle est valide, sinon le repli. */
    public static function safe(mixed $value, string $fallback = '#6366f1'): string
    {
        return self::isValid($value) ? (string) $value : $fallback;
    }
}
