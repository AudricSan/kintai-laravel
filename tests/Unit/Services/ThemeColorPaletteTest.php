<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Services;

use kintai\Core\Services\ThemeColorPalette;
use PHPUnit\Framework\TestCase;

final class ThemeColorPaletteTest extends TestCase
{
    public function testIsValidHexAcceptsSixDigitHexOnly(): void
    {
        $this->assertTrue(ThemeColorPalette::isValidHex('#ff9f4a'));
        $this->assertTrue(ThemeColorPalette::isValidHex('#FF9F4A'));
        $this->assertFalse(ThemeColorPalette::isValidHex('#fff'));
        $this->assertFalse(ThemeColorPalette::isValidHex('ff9f4a'));
        $this->assertFalse(ThemeColorPalette::isValidHex('red'));
        $this->assertFalse(ThemeColorPalette::isValidHex('#gggggg'));
    }

    public function testAutoDarkBaseIsLighterThanTheLightColorItIsDerivedFrom(): void
    {
        $dark = ThemeColorPalette::autoDarkBase('#2f86d6');

        $this->assertTrue(ThemeColorPalette::isValidHex($dark));
        $this->assertGreaterThan(self::relativeLuminance('#2f86d6'), self::relativeLuminance($dark));
    }

    public function testAutoDarkBaseFallsBackToPrimaryDefaultOnInvalidInput(): void
    {
        $dark = ThemeColorPalette::autoDarkBase('not-a-color');

        $this->assertSame(ThemeColorPalette::autoDarkBase(ThemeColorPalette::DEFAULTS['primary']), $dark);
    }

    public function testToInlineStyleSkipsGroupsLeftAtTheirDefaultInAutoMode(): void
    {
        $style = ThemeColorPalette::toInlineStyle(ThemeColorPalette::DEFAULTS, [], 'auto');

        $this->assertSame('', $style);
    }

    public function testToInlineStyleEmitsPrimaryTokensForACustomColor(): void
    {
        $style = ThemeColorPalette::toInlineStyle(['primary' => '#6c5ce7'], [], 'auto');

        $this->assertStringContainsString('--light-primary:#6c5ce7;', $style);
        $this->assertStringContainsString('--light-primary-rgb:108, 92, 231;', $style);
        $this->assertStringContainsString('--dark-primary:', $style);
        // Les autres groupes restent à leur défaut : rien émis pour eux.
        $this->assertStringNotContainsString('--light-success', $style);
    }

    public function testPrimaryUsesRealMascotColorsForItsDefaultValue(): void
    {
        // Défaut inchangé + dark manuel forcé pour ne pas être filtré : sert seulement à
        // vérifier que les teintes "toutes faites" de la mascotte sont bien utilisées.
        $style = ThemeColorPalette::toInlineStyle(
            ['primary' => ThemeColorPalette::DEFAULTS['primary']],
            ['primary' => '#111111'],
            'manual',
        );

        $this->assertStringContainsString('--light-primary-hover:#5b4a3a;', $style);
        $this->assertStringContainsString('--light-primary-light:#fff5e6;', $style);
    }

    public function testCustomPrimaryColorIsDerivedByHslInsteadOfMascotTints(): void
    {
        $style = ThemeColorPalette::toInlineStyle(['primary' => '#2f86d6'], [], 'auto');

        $this->assertStringNotContainsString('--light-primary-hover:#5b4a3a;', $style);
        $this->assertStringNotContainsString('--light-primary-light:#fff5e6;', $style);
    }

    public function testSemanticGroupWithHoverEmitsAllExpectedTokens(): void
    {
        $style = ThemeColorPalette::toInlineStyle(['success' => '#2ecc71'], [], 'auto');

        foreach ([
            '--light-success:', '--light-success-hover:', '--light-success-light:',
            '--light-success-dark:', '--light-success-border:',
            '--dark-success:', '--dark-success-hover:', '--dark-success-light:',
            '--dark-success-dark:', '--dark-success-border:',
        ] as $token) {
            $this->assertStringContainsString($token, $style);
        }
    }

    public function testInfoGroupHasNoHoverToken(): void
    {
        $style = ThemeColorPalette::toInlineStyle(['info' => '#3498db'], [], 'auto');

        $this->assertStringContainsString('--light-info:', $style);
        $this->assertStringNotContainsString('--light-info-hover', $style);
        $this->assertStringNotContainsString('--dark-info-hover', $style);
    }

    public function testFlatGroupOnlyEmitsBaseToken(): void
    {
        $style = ThemeColorPalette::toInlineStyle(['accent' => '#00aabb'], [], 'auto');

        $this->assertStringContainsString('--light-accent:#00aabb;', $style);
        $this->assertStringContainsString('--dark-accent:', $style);
        $this->assertStringNotContainsString('--light-accent-hover', $style);
    }

    public function testTableHighlightDarkVariantIsATranslucentWashOfItsOwnColor(): void
    {
        $style = ThemeColorPalette::toInlineStyle(['table_highlight' => '#00ff00'], [], 'auto');

        $this->assertStringContainsString('--light-table-highlight:#00ff00;', $style);
        $this->assertStringContainsString('--dark-table-highlight:rgba(0, 255, 0, 0.18);', $style);
    }

    public function testManualDarkModeUsesTheProvidedDarkColorInstead(): void
    {
        $style = ThemeColorPalette::toInlineStyle(
            ['danger' => '#ff0000'],
            ['danger' => '#112233'],
            'manual',
        );

        $this->assertStringContainsString('--dark-danger:#112233;', $style);
    }

    public function testManualDarkColorIsIgnoredWhenModeIsAuto(): void
    {
        $styleAuto = ThemeColorPalette::toInlineStyle(
            ['danger' => '#ff0000'],
            ['danger' => '#112233'],
            'auto',
        );

        $this->assertStringNotContainsString('#112233', $styleAuto);
    }

    public function testManualDarkAloneWithLightAtDefaultStillOverridesJustTheDarkTokens(): void
    {
        $style = ThemeColorPalette::toInlineStyle(
            ['warning' => ThemeColorPalette::DEFAULTS['warning']],
            ['warning' => '#654321'],
            'manual',
        );

        $this->assertStringContainsString('--light-warning:' . ThemeColorPalette::DEFAULTS['warning'] . ';', $style);
        $this->assertStringContainsString('--dark-warning:#654321;', $style);
    }

    public function testHoverIsDarkerThanBaseInLightThemeForPrimary(): void
    {
        $style = ThemeColorPalette::toInlineStyle(['primary' => '#2f86d6'], [], 'auto');
        [$base, $hover] = self::extractHexPair($style, '--light-primary', '--light-primary-hover');

        $this->assertLessThan(self::relativeLuminance($base), self::relativeLuminance($hover));
    }

    public function testDarkVariantIsLighterThanLightBaseForContrastOnDarkBackground(): void
    {
        $style = ThemeColorPalette::toInlineStyle(['primary' => '#2f86d6'], [], 'auto');
        [$light, $dark] = self::extractHexPair($style, '--light-primary', '--dark-primary');

        $this->assertGreaterThan(self::relativeLuminance($light), self::relativeLuminance($dark));
    }

    private static function extractHexPair(string $style, string $varA, string $varB): array
    {
        return [self::extractHex($style, $varA), self::extractHex($style, $varB)];
    }

    private static function extractHex(string $style, string $var): string
    {
        preg_match('/' . preg_quote($var, '/') . ':(#[0-9a-fA-F]{6});/', $style, $m);
        return $m[1] ?? '';
    }

    private static function relativeLuminance(string $hex): float
    {
        $r = hexdec(substr($hex, 1, 2));
        $g = hexdec(substr($hex, 3, 2));
        $b = hexdec(substr($hex, 5, 2));

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }
}
