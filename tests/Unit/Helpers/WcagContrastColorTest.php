<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Helpers;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/helpers.php';

final class WcagContrastColorTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\DataProvider('darkBackgroundProvider')]
    public function testReturnsDarkTextOnLightBackground(string $hex): void
    {
        self::assertSame('#1a1a2e', wcag_contrast_color($hex));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('lightBackgroundProvider')]
    public function testReturnsWhiteTextOnDarkBackground(string $hex): void
    {
        self::assertSame('#ffffff', wcag_contrast_color($hex));
    }

    public function testStripsAlphaSuffix(): void
    {
        // #f59e0b plein (amber) → texte foncé
        // #f59e0b70 avec alpha suffix → même résultat
        self::assertSame(wcag_contrast_color('#f59e0b'), wcag_contrast_color('#f59e0b70'));
    }

    public function testHandlesShortHex(): void
    {
        // #fff = blanc → texte foncé
        self::assertSame('#1a1a2e', wcag_contrast_color('#fff'));
        // #000 = noir → texte blanc
        self::assertSame('#ffffff', wcag_contrast_color('#000'));
    }

    public function testHandlesHexWithoutHash(): void
    {
        self::assertSame('#1a1a2e', wcag_contrast_color('ffffff'));
        self::assertSame('#ffffff', wcag_contrast_color('000000'));
    }

    public function testReturnsFallbackForInvalidInput(): void
    {
        self::assertSame('#ffffff', wcag_contrast_color(''));
        self::assertSame('#ffffff', wcag_contrast_color('#'));
    }

    /**
     * Couleurs dont la luminance > 0.20 → texte foncé '#1a1a2e'.
     * Inclut les couleurs de la palette qui posaient problème avec du texte blanc.
     */
    public static function darkBackgroundProvider(): array
    {
        return [
            'amber #f59e0b'   => ['#f59e0b'],  // L≈0.44
            'lime #84cc16'    => ['#84cc16'],  // L≈0.48
            'orange #f97316'  => ['#f97316'],  // L≈0.32
            'cyan #06b6d4'    => ['#06b6d4'],  // L≈0.38
            'emerald #10b981' => ['#10b981'],  // L≈0.36
            'red #ef4444'     => ['#ef4444'],  // L≈0.23
            'blue #3b82f6'    => ['#3b82f6'],  // L≈0.24
            'pink #ec4899'    => ['#ec4899'],  // L≈0.25
            'white'           => ['#ffffff'],  // L=1.0
            'yellow'          => ['#ffff00'],  // L≈0.93
        ];
    }

    /**
     * Couleurs dont la luminance <= 0.20 → texte blanc '#ffffff'.
     * Ce sont les fonds assez sombres où le texte blanc a le meilleur contraste.
     */
    public static function lightBackgroundProvider(): array
    {
        return [
            'indigo #6366f1' => ['#6366f1'],  // L≈0.185
            'violet #8b5cf6' => ['#8b5cf6'],  // L≈0.198
            'black'          => ['#000000'],  // L=0
        ];
    }
}
