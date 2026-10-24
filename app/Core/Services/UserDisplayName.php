<?php

declare(strict_types=1);

namespace kintai\Core\Services;

/**
 * Nom affiché d'un compte : celui saisi (espaces retirés, 200 caractères au plus) ou, s'il est vide, « Nom Prénom »
 * (ordre japonais, celui des listes du gérant). Règle commune au formulaire employé, à la création rapide et à l'API.
 */
final class UserDisplayName
{
    public const MAX_LENGTH = 200;

    public static function resolve(?string $displayName, ?string $firstName, ?string $lastName): string
    {
        $name = trim((string) $displayName);
        if ($name === '') {
            $name = trim(trim((string) $lastName) . ' ' . trim((string) $firstName));
        }
        return mb_substr($name, 0, self::MAX_LENGTH);
    }
}
