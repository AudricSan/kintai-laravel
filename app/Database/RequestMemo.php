<?php

declare(strict_types=1);

namespace App\Database;

use Closure;

/**
 * Mémoire des lectures de comptes et de droits pour la durée d'une requête.
 *
 * Mesuré sur l'instance de démonstration : une page relisait le compte connecté, ses affectations de rôle et ses rôles
 * jusqu'à trente fois (chaque middleware, chaque contrôle de droit), soit la moitié de ses requêtes SQL. Ces lectures
 * passent par ici ; la mémoire est vidée à chaque écriture d'un compte, d'un rôle, d'une affectation ou d'une
 * permission (voir FlushesRequestMemo et DatabaseRoleRepository), et ne survit pas à la requête (un processus PHP par
 * requête). Rien n'est gardé d'une requête à l'autre : aucun risque de droit périmé.
 */
final class RequestMemo
{
    /** @var array<string, mixed> */
    private static array $values = [];

    /**
     * @template T
     * @param Closure(): T $compute
     * @return T
     */
    public static function remember(string $key, Closure $compute): mixed
    {
        if (array_key_exists($key, self::$values)) {
            return self::$values[$key];
        }

        return self::$values[$key] = $compute();
    }

    public static function flush(): void
    {
        self::$values = [];
    }
}
