<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\UI;

use PHPUnit\Framework\TestCase;

/**
 * Bandeaux collants au-dessus de la barre du haut (rappel de mot de passe, maintenance) : la teinte d'avertissement
 * est translucide en thème sombre (rgba), leur fond doit donc la poser sur la couleur de surface opaque.
 */
final class AppBannerCssTest extends TestCase
{
    public function testBannerTintIsLaidOverAnOpaqueSurface(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 3) . '/public/assets/css/src/components/app-banner.css');
        $this->assertSame(1, preg_match('/\.app-banner\s*\{([^}]*)\}/', $css, $m));
        $this->assertMatchesRegularExpression('/background:[^;]*var\(--color-warning-light\)[^;]*,\s*var\(--color-surface\)\s*;/', $m[1]);
    }
}
