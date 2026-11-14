<?php

namespace App\Settings;

/**
 * Couleurs de marque personnalisables par l'Owner (7 groupes) et dérivation de leurs variantes (survol, teintes
 * claires, bordure, version sombre). Les couleurs sont émises en variables CSS `--kintai-<groupe>[-<variante>]` :
 * valeurs claires sous `:root`, valeurs sombres sous `.dark`. La couleur primaire pilote aussi l'accent de Flux
 * (`--color-accent*`). Un groupe laissé à sa valeur par défaut, sans couleur sombre manuelle, n'émet rien.
 *
 * Mode sombre `auto` : les variantes sombres sont calculées (éclaircissement HSL) ; `manual` : celles de l'Owner
 * priment quand elles sont renseignées. Seules des valeurs `#rrggbb` validées entrent dans la feuille de style.
 */
final class ThemeColorPalette
{
    /** Couleur par défaut de chaque groupe (reprises de l'ancienne version). */
    public const DEFAULTS = [
        'primary' => '#ff9f4a',
        'accent' => '#4caf50',
        'table_highlight' => '#dff5e1',
        'success' => '#1fae6b',
        'warning' => '#c97a12',
        'danger' => '#e5484d',
        'info' => '#2f86d6',
    ];

    private const SHAPE = [
        'primary' => 'semantic',
        'accent' => 'flat',
        'table_highlight' => 'translucent',
        'success' => 'semantic',
        'warning' => 'semantic',
        'danger' => 'semantic',
        'info' => 'semantic',
    ];

    public static function isValidHex(string $hex): bool
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', $hex) === 1;
    }

    /**
     * Couleur sombre calculée à partir d'une couleur claire (point de départ proposé en mode manuel).
     */
    public static function autoDarkBase(string $hex): string
    {
        if (! self::isValidHex($hex)) {
            $hex = self::DEFAULTS['primary'];
        }

        [$h, $s, $l] = self::hexToHsl($hex);

        return self::autoLighten($h, $s, $l);
    }

    /**
     * Feuille de style des couleurs personnalisées ; chaîne vide si rien n'est personnalisé.
     *
     * @param  array<string, string>  $lightColors  groupe => #rrggbb
     * @param  array<string, string>  $darkColors  groupe => #rrggbb ('' = calculée) ; ignoré hors mode `manual`
     */
    public static function toCss(array $lightColors, array $darkColors, string $darkMode): string
    {
        $root = '';
        $dark = '';

        foreach (self::DEFAULTS as $group => $default) {
            $light = $lightColors[$group] ?? '';
            $lightIsDefault = ! self::isValidHex($light) || strcasecmp($light, $default) === 0;

            $manualDark = $darkMode === 'manual' ? ($darkColors[$group] ?? '') : '';
            $hasManualDark = self::isValidHex($manualDark);

            if ($lightIsDefault && ! $hasManualDark) {
                continue;
            }

            $tokens = self::generate($group, $lightIsDefault ? $default : $light, $hasManualDark ? $manualDark : null);

            foreach ($tokens as $name => $value) {
                if (str_starts_with($name, 'light-')) {
                    $root .= '--kintai-'.substr($name, 6).':'.$value.';';
                } else {
                    $dark .= '--kintai-'.substr($name, 5).':'.$value.';';
                }
            }

            if ($group === 'primary') {
                $root .= self::accent($tokens['light-primary']);
                $dark .= self::accent($tokens['dark-primary']);
            }
        }

        return $root === '' ? '' : ':root{'.$root.'}.dark{'.$dark.'}';
    }

    /**
     * Accent de Flux : la couleur primaire, avec un texte noir ou blanc selon sa luminosité.
     */
    private static function accent(string $hex): string
    {
        [$r, $g, $b] = self::hexToRgb($hex);
        $foreground = (0.299 * $r + 0.587 * $g + 0.114 * $b) > 150 ? '#171717' : '#ffffff';

        return "--color-accent:{$hex};--color-accent-content:{$hex};--color-accent-foreground:{$foreground};";
    }

    /**
     * @return array<string, string> `light-<nom>` / `dark-<nom>` => valeur CSS
     */
    private static function generate(string $group, string $hex, ?string $manualDark): array
    {
        $name = str_replace('_', '-', $group);
        [$h, $s, $l] = self::hexToHsl($hex);
        $darkBase = $manualDark ?? self::autoLighten($h, $s, $l);
        [$dh, $ds, $dl] = self::hexToHsl($darkBase);
        [$dr, $dg, $db] = self::hexToRgb($darkBase);

        return match (self::SHAPE[$group]) {
            'flat' => [
                "light-$name" => $hex,
                "dark-$name" => $darkBase,
            ],
            'translucent' => [
                "light-$name" => $hex,
                "dark-$name" => sprintf('rgba(%d, %d, %d, 0.18)', $dr, $dg, $db),
            ],
            default => [
                "light-$name" => $hex,
                "light-$name-hover" => self::hslToHex($h, $s, max(0.0, $l - 0.12)),
                "light-$name-light" => self::tint($hex, 0.85),
                "light-$name-dark" => self::hslToHex($h, min(1.0, $s + 0.05), max(0.0, $l - 0.32)),
                "light-$name-border" => self::tint($hex, 0.55),
                "dark-$name" => $darkBase,
                "dark-$name-hover" => self::hslToHex($dh, $ds, min(0.96, $dl + 0.08)),
                "dark-$name-light" => sprintf('rgba(%d, %d, %d, 0.16)', $dr, $dg, $db),
                "dark-$name-dark" => self::hslToHex($dh, $ds, min(0.98, $dl + 0.14)),
                "dark-$name-border" => $darkBase,
            ],
        };
    }

    private static function autoLighten(float $h, float $s, float $l): string
    {
        return self::hslToHex($h, max(0.0, $s - 0.05), min(0.92, $l + 0.18));
    }

    private static function tint(string $hex, float $whiteRatio): string
    {
        [$r, $g, $b] = self::hexToRgb($hex);
        $mix = static fn (int $c): int => (int) round($c * (1 - $whiteRatio) + 255 * $whiteRatio);

        return self::rgbToHex($mix($r), $mix($g), $mix($b));
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private static function hexToRgb(string $hex): array
    {
        return [(int) hexdec(substr($hex, 1, 2)), (int) hexdec(substr($hex, 3, 2)), (int) hexdec(substr($hex, 5, 2))];
    }

    private static function rgbToHex(int $r, int $g, int $b): string
    {
        return sprintf('#%02x%02x%02x', max(0, min(255, $r)), max(0, min(255, $g)), max(0, min(255, $b)));
    }

    /**
     * @return array{0: float, 1: float, 2: float} teinte (0-360), saturation et luminosité (0-1)
     */
    private static function hexToHsl(string $hex): array
    {
        [$r, $g, $b] = self::hexToRgb($hex);
        $r /= 255;
        $g /= 255;
        $b /= 255;
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;

        if ($max === $min) {
            return [0.0, 0.0, $l];
        }

        $delta = $max - $min;
        $s = $l > 0.5 ? $delta / (2 - $max - $min) : $delta / ($max + $min);

        $h = match ($max) {
            $r => fmod(($g - $b) / $delta, 6),
            $g => ($b - $r) / $delta + 2,
            default => ($r - $g) / $delta + 4,
        };
        $h *= 60;

        return [$h < 0 ? $h + 360 : $h, $s, $l];
    }

    private static function hslToHex(float $h, float $s, float $l): string
    {
        $s = max(0.0, min(1.0, $s));
        $l = max(0.0, min(1.0, $l));

        $c = (1 - abs(2 * $l - 1)) * $s;
        $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
        $m = $l - $c / 2;

        [$r, $g, $b] = match (true) {
            $h < 60 => [$c, $x, 0.0],
            $h < 120 => [$x, $c, 0.0],
            $h < 180 => [0.0, $c, $x],
            $h < 240 => [0.0, $x, $c],
            $h < 300 => [$x, 0.0, $c],
            default => [$c, 0.0, $x],
        };

        return self::rgbToHex((int) round(($r + $m) * 255), (int) round(($g + $m) * 255), (int) round(($b + $m) * 255));
    }
}
