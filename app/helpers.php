<?php

declare(strict_types=1);


/**
 * Formate un montant selon la devise du store.
 *
 * JPY → pas de décimales, séparateur milliers ".", symbole 円 ou ¥ en suffixe selon $jpySymbolStyle
 * EUR → 2 décimales, séparateur décimal ",", milliers ".", symbole "€" en suffixe
 * USD → 2 décimales, séparateur décimal ".", milliers ",", symbole "$" en préfixe
 * Autres → 2 décimales, code ISO en suffixe
 */
function format_currency(float $amount, string $currency = 'EUR', string $jpySymbolStyle = 'kanji'): string
{
    $currency = strtoupper(trim($currency));
    $symbol   = currency_symbol($currency, $jpySymbolStyle);

    return match ($currency) {
        'JPY'   => number_format($amount, 0, '.', ',') . $symbol,
        'KRW'   => number_format($amount, 0, '.', ',') . $symbol,
        'EUR'   => number_format($amount, 2, ',', '.') . ' ' . $symbol,
        'USD'   => $symbol . number_format($amount, 2, '.', ','),
        'GBP'   => $symbol . number_format($amount, 2, '.', ','),
        'CHF'   => $symbol . ' ' . number_format($amount, 2, '.', '\''),
        default => number_format($amount, 2, '.', ',') . ' ' . $symbol,
    };
}

/**
 * Retourne le symbole d'affichage d'une devise (ex. JPY → 円 ou ¥, EUR → €).
 * $jpySymbolStyle : 'kanji' (円, défaut) ou 'international' (¥) — n'a d'effet que pour JPY.
 */
function currency_symbol(string $currency, string $jpySymbolStyle = 'kanji'): string
{
    $currency = strtoupper(trim($currency));
    if ($currency === 'JPY') {
        return $jpySymbolStyle === 'international' ? '¥' : '円';
    }
    return match ($currency) {
        'KRW'   => '₩',
        'EUR'   => '€',
        'USD'   => '$',
        'GBP'   => '£',
        'CHF'   => 'CHF',
        // Code inconnu : réduit à ses lettres et chiffres. Il est affiché tel quel par plusieurs vues et
        // exports ; une valeur forgée (« <A HREF=…> ») ne doit jamais y injecter de balisage.
        default => (string) preg_replace('/[^A-Z0-9]/', '', $currency),
    };
}

/**
 * Style d'affichage du symbole yen configuré sur un store ('kanji' ou 'international').
 * Toute valeur inconnue retombe sur 'kanji' (comportement historique).
 */
function store_currency_style(?array $store): string
{
    $style = $store['currency_symbol_style'] ?? 'kanji';
    return $style === 'international' ? 'international' : 'kanji';
}

/**
 * Retourne '#ffffff' ou '#1a1a2e' selon la luminance WCAG du fond hexadécimal.
 * Les suffixes alpha (ex. '#6366f170') sont ignorés — seuls les 6 premiers chiffres hex comptent.
 */
function wcag_contrast_color(string $hex): string
{
    $hex = ltrim($hex, '#');
    $hex = substr($hex, 0, 6);
    if (strlen($hex) === 3) {
        $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    }
    if (strlen($hex) < 6) return '#ffffff';
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    $lin = static fn(int $c): float => ($c / 255) <= 0.04045
        ? ($c / 255) / 12.92
        : (($c / 255 + 0.055) / 1.055) ** 2.4;
    $L = 0.2126 * $lin($r) + 0.7152 * $lin($g) + 0.0722 * $lin($b);
    // Seuil 0.20 = croisement d'équicontraste entre '#ffffff' et '#1a1a2e' (L≈0.012)
    return $L > 0.20 ? '#1a1a2e' : '#ffffff';
}

/**
 * Nonce CSP de la requête, à mettre sur chaque <script> inline : `<script nonce="<?= csp_nonce() ?>">`.
 * Un script inline sans ce nonce est bloqué par la Content-Security-Policy.
 */
function csp_nonce(): string
{
    return \App\Security\CspNonce::get();
}

/**
 * Classe CSS portant des déclarations dynamiques (couleur, largeur…) à la place d'un attribut style="…" :
 * `<div class="bar <?= style_class(['width' => $pct . '%']) ?>">`. Voir App\View\InlineStyles.
 */
function style_class(array $declarations): string
{
    return \App\View\InlineStyles::classFor($declarations);
}

/**
 * Bloc <style nonce> des règles enregistrées par style_class() (vide s'il n'y en a pas). Émis par les layouts ;
 * une page autonome (impression, aperçu PDF) l'appelle elle-même avant </body>.
 */
function inline_styles(): string
{
    $css = \App\View\InlineStyles::pull();
    return $css === '' ? '' : '<style nonce="' . csp_nonce() . '">' . $css . '</style>';
}
