<?php

namespace Kintai\Bundles\Analytics;

use App\Models\Store;
use App\Support\Money;

/**
 * Mise en forme des pages et des widgets : montants selon la devise et le style de symbole du magasin (`Money` du Core),
 * noms d'employés, pourcentages et largeur des barres. Les services renvoient des nombres bruts.
 */
final class Presenter
{
    public static function money(float|int $amount, Store $store): string
    {
        return Money::forStore($amount, $store);
    }

    /**
     * @param  array<int, array<string, mixed>>  $usersMap
     */
    public static function name(array $usersMap, int $userId): string
    {
        $user = $usersMap[$userId] ?? null;
        if ($user === null) {
            return '#'.$userId;
        }

        $name = trim((string) ($user['name'] ?? ''));
        if ($name === '') {
            $name = trim(((string) ($user['last_name'] ?? '')).' '.((string) ($user['first_name'] ?? '')));
        }

        return $name !== '' ? $name : ((string) ($user['email'] ?? '#'.$userId));
    }

    public static function percent(int|float $part, int|float $total): string
    {
        return $total > 0 ? round($part / $total * 100, 1).'%' : '—';
    }

    /**
     * Largeur d'une barre (0 à 100) : jamais de division par zéro.
     */
    public static function width(int|float $value, int|float $max): int
    {
        return $max > 0 ? (int) max(0, min(100, round($value / $max * 100))) : 0;
    }

    /**
     * Couleur Flux d'un score : plus c'est haut, mieux c'est.
     */
    public static function tone(int|float $score): string
    {
        return $score >= 70 ? 'green' : ($score >= 40 ? 'amber' : 'red');
    }
}
