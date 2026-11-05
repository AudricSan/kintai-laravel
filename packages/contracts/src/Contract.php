<?php

namespace Kintai\Contracts;

use InvalidArgumentException;

/**
 * Version du contrat Core / bundles (semver). Un bundle déclare la version qu'il exige (`kintai/contracts` dans son
 * `composer.json`, et `requiredContract()` dans son service provider) : le Core refuse d'activer un bundle dont la
 * contrainte n'est pas satisfaite.
 *
 * Ce qui casse le contrat (donc une version majeure) : retirer ou renommer une interface, une méthode, un événement ou
 * une propriété d'événement, changer la signature d'une méthode, ajouter une méthode à une interface que les bundles
 * implémentent. Un ajout compatible (nouvelle classe, nouvel argument optionnel, nouvelle méthode d'une interface que
 * seul le Core implémente) est une version mineure.
 */
final class Contract
{
    public const VERSION = '1.2.0';

    /**
     * La version courante du contrat satisfait-elle la contrainte ? Syntaxe acceptée (comme Composer, sous-ensemble) :
     * `^1.0`, `~1.2`, `1.*`, `1.2.3`, `>=1.0`, `>=1.0 <2.0`, et des alternatives séparées par `||` ou `|`.
     */
    public static function satisfies(string $constraint, string $version = self::VERSION): bool
    {
        $constraint = trim($constraint);

        if ($constraint === '') {
            throw new InvalidArgumentException('The contract constraint is empty.');
        }

        foreach (preg_split('/\s*\|\|?\s*/', $constraint) ?: [] as $alternative) {
            if (self::satisfiesAll($alternative, $version)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Version majeure du contrat courant.
     */
    public static function major(): int
    {
        return (int) explode('.', self::VERSION)[0];
    }

    private static function satisfiesAll(string $alternative, string $version): bool
    {
        // Les contraintes d'une même alternative se combinent par espace ou virgule.
        $parts = preg_split('/\s*,\s*|\s+(?=[\^~<>=!\d*])/', trim($alternative)) ?: [];

        foreach ($parts as $part) {
            if ($part === '' || ! self::satisfiesOne($part, $version)) {
                return false;
            }
        }

        return $parts !== [];
    }

    private static function satisfiesOne(string $constraint, string $version): bool
    {
        if (preg_match('/^(\^|~|>=|<=|>|<|=|==)?\s*v?(\d+)(?:\.(\d+|[*xX]))?(?:\.(\d+|[*xX]))?$/', $constraint, $m) !== 1) {
            throw new InvalidArgumentException("The contract constraint [{$constraint}] is not supported.");
        }

        $operator = $m[1];
        $major = (int) $m[2];
        $minorGiven = isset($m[3]) && ! in_array($m[3], ['*', 'x', 'X'], true);
        $patchGiven = isset($m[4]) && ! in_array($m[4], ['*', 'x', 'X'], true);
        $minor = $minorGiven ? (int) $m[3] : 0;
        $patch = $patchGiven ? (int) $m[4] : 0;
        $floor = [$major, $minor, $patch];
        $current = array_map('intval', array_pad(explode('.', $version), 3, '0'));

        return match ($operator) {
            '^' => self::compare($current, $floor) >= 0 && self::compare($current, self::caretCeiling($major, $minor, $patch, $minorGiven)) < 0,
            '~' => self::compare($current, $floor) >= 0 && self::compare($current, $patchGiven ? [$major, $minor + 1, 0] : [$major + 1, 0, 0]) < 0,
            '>=' => self::compare($current, $floor) >= 0,
            '>' => self::compare($current, $floor) > 0,
            '<=' => self::compare($current, $floor) <= 0,
            '<' => self::compare($current, $floor) < 0,
            default => $minorGiven && $patchGiven
                ? self::compare($current, $floor) === 0
                : self::compare($current, $floor) >= 0 && self::compare($current, $minorGiven ? [$major, $minor + 1, 0] : [$major + 1, 0, 0]) < 0,
        };
    }

    /**
     * @return array{int, int, int}
     */
    private static function caretCeiling(int $major, int $minor, int $patch, bool $minorGiven): array
    {
        if ($major > 0 || ! $minorGiven) {
            return [$major + 1, 0, 0];
        }

        return $minor > 0 ? [0, $minor + 1, 0] : [0, 0, $patch + 1];
    }

    /**
     * @param  array<int, int>  $a
     * @param  array<int, int>  $b
     */
    private static function compare(array $a, array $b): int
    {
        return $a <=> $b;
    }
}
