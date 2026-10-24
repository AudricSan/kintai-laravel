<?php

declare(strict_types=1);

namespace kintai\Contract;

/**
 * Contrainte de version au format Composer, réduit à ce dont un bundle a besoin :
 *
 * - `1.2.3` ou `=1.2.3` : exactement cette version ;
 * - `^1.2` : >= 1.2.0 et < 2.0.0 (`^0.3` : >= 0.3.0 et < 0.4.0, comme Composer) ;
 * - `~1.2` : >= 1.2.0 et < 1.3.0 si le correctif est donné (`~1.2.3`), sinon < 2.0.0 ;
 * - `>=`, `>`, `<=`, `<` suivis d'une version ;
 * - `*` : toute version ;
 * - plusieurs contraintes séparées par un espace (ET) ou par `||` (OU).
 *
 * Les versions sont X, X.Y ou X.Y.Z (les composantes manquantes valent 0) ; un préfixe `v` est accepté.
 */
final class VersionConstraint
{
    /** @param list<list<array{string, string}>> $alternatives OU de ET de (opérateur, version) */
    private function __construct(private readonly array $alternatives) {}

    /** Contrainte analysée, ou null si elle est mal formée. */
    public static function parse(string $constraint): ?self
    {
        $alternatives = [];
        foreach (explode('||', $constraint) as $alternative) {
            $terms = preg_split('/\s+/', trim($alternative), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if ($terms === []) {
                return null;
            }
            $conditions = [];
            foreach ($terms as $term) {
                $parsed = self::parseTerm($term);
                if ($parsed === null) {
                    return null;
                }
                array_push($conditions, ...$parsed);
            }
            $alternatives[] = $conditions;
        }
        return new self($alternatives);
    }

    public function allows(string $version): bool
    {
        $v = self::normalize($version);
        if ($v === null) {
            return false;
        }
        foreach ($this->alternatives as $conditions) {
            $ok = true;
            foreach ($conditions as [$op, $bound]) {
                if (!version_compare($v, $bound, $op)) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                return true;
            }
        }
        return false;
    }

    /** @return list<array{string, string}>|null */
    private static function parseTerm(string $term): ?array
    {
        if ($term === '*') {
            return [['>=', '0.0.0']];
        }
        if (preg_match('/^(\^|~|>=|<=|>|<|=)?v?(\d+)(?:\.(\d+))?(?:\.(\d+))?$/', $term, $m) !== 1) {
            return null;
        }
        $op = $m[1] ?? '';
        [$major, $minor, $patch] = [(int) $m[2], (int) ($m[3] ?? 0), (int) ($m[4] ?? 0)];
        $hasMinor = ($m[3] ?? '') !== '';
        $hasPatch = ($m[4] ?? '') !== '';
        $base = "$major.$minor.$patch";

        return match ($op) {
            '^' => [['>=', $base], ['<', self::caretUpperBound($major, $minor, $patch, $hasMinor, $hasPatch)]],
            '~' => [['>=', $base], ['<', $hasPatch ? "$major." . ($minor + 1) . '.0' : ($major + 1) . '.0.0']],
            '', '=' => [['==', $base]],
            default => [[$op, $base]],
        };
    }

    /**
     * Borne exclue de `^` (règles de Composer) : le premier chiffre non nul ne change pas.
     * ^1.2.3 -> 2.0.0 ; ^0.3 -> 0.4.0 ; ^0.0.3 -> 0.0.4 ; ^0.0 -> 0.1.0 ; ^0 -> 1.0.0.
     */
    private static function caretUpperBound(int $major, int $minor, int $patch, bool $hasMinor, bool $hasPatch): string
    {
        if ($major > 0 || !$hasMinor) {
            return ($major + 1) . '.0.0';
        }
        if ($minor > 0 || !$hasPatch) {
            return '0.' . ($minor + 1) . '.0';
        }
        return '0.0.' . ($patch + 1);
    }

    private static function normalize(string $version): ?string
    {
        if (preg_match('/^v?(\d+)(?:\.(\d+))?(?:\.(\d+))?/', trim($version), $m) !== 1) {
            return null;
        }
        return (int) $m[1] . '.' . (int) ($m[2] ?? 0) . '.' . (int) ($m[3] ?? 0);
    }
}
