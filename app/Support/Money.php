<?php

namespace App\Support;

use App\Models\Store;

/**
 * Formatage des montants selon la devise du magasin, comme l'ancienne version (format_currency).
 *
 * JPY et KRW : sans décimales, symbole en suffixe (JPY : « 円 » ou « ¥ » selon `currency_symbol_style`).
 * EUR : « 1.234,56 € ». USD et GBP : symbole en préfixe. CHF : « CHF 1'234.56 ». Autres : code ISO en suffixe.
 */
final class Money
{
    public const STYLE_KANJI = 'kanji';

    public const STYLE_INTERNATIONAL = 'international';

    public static function format(float|int|string $amount, string $currency, string $style = self::STYLE_KANJI): string
    {
        $amount = (float) $amount;
        $currency = strtoupper(trim($currency));
        $symbol = self::symbol($currency, $style);

        return match ($currency) {
            'JPY', 'KRW' => number_format($amount, 0, '.', ',').$symbol,
            'EUR' => number_format($amount, 2, ',', '.').' '.$symbol,
            'USD', 'GBP' => $symbol.number_format($amount, 2, '.', ','),
            'CHF' => $symbol.' '.number_format($amount, 2, '.', '\''),
            default => number_format($amount, 2, '.', ',').' '.$symbol,
        };
    }

    public static function forStore(float|int|string $amount, Store $store): string
    {
        return self::format($amount, $store->currency, $store->currency_symbol_style);
    }

    /**
     * Le style n'a d'effet que pour le yen ; toute valeur inconnue retombe sur « kanji ».
     */
    public static function symbol(string $currency, string $style = self::STYLE_KANJI): string
    {
        $currency = strtoupper(trim($currency));

        if ($currency === 'JPY') {
            return $style === self::STYLE_INTERNATIONAL ? '¥' : '円';
        }

        return match ($currency) {
            'KRW' => '₩',
            'EUR' => '€',
            'USD' => '$',
            'GBP' => '£',
            'CHF' => 'CHF',
            // Code inconnu : réduit à ses lettres et chiffres, jamais de balisage.
            default => (string) preg_replace('/[^A-Z0-9]/', '', $currency),
        };
    }
}
