<?php

declare(strict_types=1);

namespace kintai\Core\Services;

/**
 * Dérive, pour chaque couleur de marque personnalisable par l'Owner (primaire, accent,
 * survol de tableau, et les 4 couleurs sémantiques succès/avertissement/danger/info),
 * l'ensemble des variantes CSS (hover, teintes claires, bordure, version mode sombre)
 * attendues par variables.css — à injecter en style inline sur <html>. Voir les tokens
 * --light-xxx et --dark-xxx de ce fichier pour l'indirection qui permet cette surcharge
 * sans toucher --color-xxx directement (donc sans casser la bascule clair/sombre existante).
 *
 * Remplace l'ancien PrimaryColorPalette, qui ne couvrait que "primary" : ce groupe reste
 * un cas particulier (teintes officielles de la mascotte Foxy pour sa valeur par défaut),
 * les 6 autres groupes sont dérivés uniquement par calcul HSL, qu'ils soient à leur valeur
 * par défaut ou personnalisés.
 */
final class ThemeColorPalette
{
    public const DEFAULT_DARK_MODE = 'auto';

    /** Couleur par défaut de chaque groupe — celle codée en dur dans variables.css. */
    public const DEFAULTS = [
        'primary'         => '#ff9f4a',
        'accent'          => '#4caf50',
        'table_highlight' => '#dff5e1',
        'success'         => '#1fae6b',
        'warning'         => '#c97a12',
        'danger'          => '#e5484d',
        'info'            => '#2f86d6',
    ];

    private const SHAPE = [
        'primary'         => 'primary',
        'accent'          => 'flat',
        'table_highlight' => 'flat_translucent',
        'success'         => 'semantic',
        'warning'         => 'semantic',
        'danger'          => 'semantic',
        'info'            => 'semantic_no_hover',
    ];

    /** Nez/pattes puis ventre de Foxy — teintes de la couleur primaire par défaut uniquement. */
    private const PRIMARY_DEFAULT_HOVER = '#5b4a3a';
    private const PRIMARY_DEFAULT_LIGHT = '#fff5e6';
    private const PRIMARY_DEFAULT_LIGHTER = '#fffaf3';

    public static function isValidHex(string $hex): bool
    {
        return (bool) preg_match('/^#[0-9a-fA-F]{6}$/', $hex);
    }

    /**
     * Teinte sombre calculée automatiquement à partir d'une couleur claire — utilisée pour
     * pré-remplir le sélecteur "variante sombre" du mode manuel avec un point de départ
     * cohérent (au lieu de la couleur claire telle quelle, illisible une fois basculée sur
     * fond sombre si l'Owner enregistre sans y toucher).
     */
    public static function autoDarkBase(string $hex): string
    {
        if (!self::isValidHex($hex)) {
            $hex = self::DEFAULTS['primary'];
        }
        [$h, $s, $l] = self::hexToHsl($hex);
        return self::autoLighten($h, $s, $l);
    }

    /**
     * @param array<string, string> $lightColors group => hex ; un groupe absent, invalide ou
     *                                            égal à sa valeur par défaut n'est pas surchargé
     *                                            (les valeurs codées en dur de variables.css
     *                                            s'appliquent, pour ne rien changer visuellement
     *                                            tant que l'Owner n'a rien personnalisé).
     * @param array<string, string> $darkColors  group => hex ; ignoré si $darkMode !== 'manual'
     */
    public static function toInlineStyle(array $lightColors, array $darkColors, string $darkMode): string
    {
        $css = '';
        foreach (self::DEFAULTS as $group => $default) {
            $light = $lightColors[$group] ?? '';
            $lightIsDefault = !self::isValidHex($light) || strcasecmp($light, $default) === 0;

            $manualDark = $darkMode === 'manual' ? ($darkColors[$group] ?? '') : '';
            $hasManualDark = self::isValidHex($manualDark);

            if ($lightIsDefault && !$hasManualDark) {
                continue;
            }

            $effectiveLight = $lightIsDefault ? $default : $light;
            foreach (self::generateGroup($group, $effectiveLight, $hasManualDark ? $manualDark : null) as $var => $value) {
                $css .= $var . ':' . $value . ';';
            }
        }
        return $css;
    }

    /** @return array<string, string> */
    private static function generateGroup(string $group, string $lightHex, ?string $manualDarkHex): array
    {
        // Les clés de groupe (snake_case, utilisées aussi comme noms de réglages) ne
        // correspondent pas forcément au nom du token CSS (kebab-case, ex. table_highlight
        // → --light-table-highlight).
        $cssName = str_replace('_', '-', $group);

        return match (self::SHAPE[$group]) {
            'primary'          => self::generatePrimary($lightHex, $manualDarkHex),
            'flat'              => self::generateFlat($cssName, $lightHex, $manualDarkHex),
            'flat_translucent'  => self::generateFlatTranslucent($cssName, $lightHex, $manualDarkHex),
            'semantic'          => self::generateSemantic($cssName, $lightHex, $manualDarkHex, true),
            'semantic_no_hover' => self::generateSemantic($cssName, $lightHex, $manualDarkHex, false),
        };
    }

    private static function generatePrimary(string $hex, ?string $manualDarkHex): array
    {
        [$h, $s, $l] = self::hexToHsl($hex);
        [$r, $g, $b] = self::hexToRgb($hex);
        $isDefault = strcasecmp($hex, self::DEFAULTS['primary']) === 0;

        $darkBase = $manualDarkHex ?? self::autoLighten($h, $s, $l);
        [$dh, $ds, $dl] = self::hexToHsl($darkBase);
        [$dr, $dg, $db] = self::hexToRgb($darkBase);

        return [
            '--light-primary'         => $hex,
            '--light-primary-rgb'     => "$r, $g, $b",
            '--light-primary-hover'   => $isDefault ? self::PRIMARY_DEFAULT_HOVER : self::hslToHex($h, $s, max(0.0, $l - 0.12)),
            '--light-primary-light'   => $isDefault ? self::PRIMARY_DEFAULT_LIGHT : self::tint($hex, 0.88),
            '--light-primary-lighter' => $isDefault ? self::PRIMARY_DEFAULT_LIGHTER : self::tint($hex, 0.94),
            '--light-primary-medium'  => self::tint($hex, 0.68),
            '--dark-primary'          => $darkBase,
            '--dark-primary-hover'    => self::hslToHex($dh, $ds, min(0.96, $dl + 0.08)),
            '--dark-primary-light'    => sprintf('rgba(%d, %d, %d, 0.16)', $dr, $dg, $db),
            '--dark-primary-lighter'  => sprintf('rgba(%d, %d, %d, 0.08)', $dr, $dg, $db),
            '--dark-primary-medium'   => sprintf('rgba(%d, %d, %d, 0.30)', $dr, $dg, $db),
        ];
    }

    /** Groupe à une seule teinte pleine (accent) : pas de hover/light/dark/border dérivés. */
    private static function generateFlat(string $group, string $hex, ?string $manualDarkHex): array
    {
        [$h, $s, $l] = self::hexToHsl($hex);

        return [
            "--light-$group" => $hex,
            "--dark-$group"  => $manualDarkHex ?? self::autoLighten($h, $s, $l),
        ];
    }

    /** Groupe dont la variante sombre est un lavis translucide (survol de tableau). */
    private static function generateFlatTranslucent(string $group, string $hex, ?string $manualDarkHex): array
    {
        [$r, $g, $b] = self::hexToRgb($manualDarkHex ?? $hex);

        return [
            "--light-$group" => $hex,
            "--dark-$group"  => sprintf('rgba(%d, %d, %d, 0.18)', $r, $g, $b),
        ];
    }

    /** Groupe sémantique (succès/avertissement/danger/info) : base, hover?, teinte claire, teinte foncée, bordure. */
    private static function generateSemantic(string $group, string $hex, ?string $manualDarkHex, bool $withHover): array
    {
        [$h, $s, $l] = self::hexToHsl($hex);

        $tokens = [
            "--light-$group"        => $hex,
            "--light-$group-light"  => self::tint($hex, 0.85),
            "--light-$group-dark"   => self::hslToHex($h, min(1.0, $s + 0.05), max(0.0, $l - 0.32)),
            "--light-$group-border" => self::tint($hex, 0.55),
        ];
        if ($withHover) {
            $tokens["--light-$group-hover"] = self::hslToHex($h, $s, max(0.0, $l - 0.12));
        }

        $darkBase = $manualDarkHex ?? self::autoLighten($h, $s, $l);
        [$dh, $ds, $dl] = self::hexToHsl($darkBase);
        [$dr, $dg, $db] = self::hexToRgb($darkBase);

        $tokens["--dark-$group"] = $darkBase;
        if ($withHover) {
            $tokens["--dark-$group-hover"] = self::hslToHex($dh, $ds, min(0.96, $dl + 0.08));
        }
        $tokens["--dark-$group-light"]  = sprintf('rgba(%d, %d, %d, 0.16)', $dr, $dg, $db);
        $tokens["--dark-$group-dark"]   = self::hslToHex($dh, $ds, min(0.98, $dl + 0.14));
        $tokens["--dark-$group-border"] = $darkBase;

        return $tokens;
    }

    /** Éclaircit/désature légèrement une teinte claire pour qu'elle reste lisible sur fond sombre. */
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

    /** @return array{0:int,1:int,2:int} */
    private static function hexToRgb(string $hex): array
    {
        return [
            hexdec(substr($hex, 1, 2)),
            hexdec(substr($hex, 3, 2)),
            hexdec(substr($hex, 5, 2)),
        ];
    }

    private static function rgbToHex(int $r, int $g, int $b): string
    {
        return sprintf('#%02x%02x%02x', max(0, min(255, $r)), max(0, min(255, $g)), max(0, min(255, $b)));
    }

    /** @return array{0:float,1:float,2:float} teinte (0-360), saturation et luminosité (0-1) */
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
        if ($h < 0) {
            $h += 360;
        }

        return [$h, $s, $l];
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

        return self::rgbToHex(
            (int) round(($r + $m) * 255),
            (int) round(($g + $m) * 255),
            (int) round(($b + $m) * 255),
        );
    }
}
