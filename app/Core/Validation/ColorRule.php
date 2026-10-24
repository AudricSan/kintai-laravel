<?php

declare(strict_types=1);

namespace kintai\Core\Validation;

use kintai\Core\Exceptions\ValidationException;

/**
 * Règle unique pour toute couleur saisie par un utilisateur (types de shift, comptes, rôles).
 *
 * Seul le format hexadécimal #RGB / #RRGGBB est accepté : une valeur libre finissait telle quelle dans
 * un attribut style="" (injection CSS stockée, par exemple « red;position:fixed;… »).
 */
final class ColorRule
{
    public static function isValid(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})\z/', $value) === 1;
    }

    /**
     * Refuse (422) toute valeur qui n'est pas une couleur hexadécimale.
     * Avec $nullable, null et la chaîne vide sont acceptés (couleur facultative).
     */
    public static function assert(mixed $value, string $field = 'color', bool $nullable = false): void
    {
        if ($nullable && ($value === null || $value === '')) {
            return;
        }
        if (!self::isValid($value)) {
            throw new ValidationException([$field => __('error_invalid_color')]);
        }
    }

    /** Valeur sûre à injecter dans un attribut : la couleur si elle est valide, sinon le repli. */
    public static function safe(mixed $value, string $fallback = '#6366f1'): string
    {
        return self::isValid($value) ? (string) $value : $fallback;
    }
}
