<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\UI;

use kintai\UI\InlineStyles;
use PHPUnit\Framework\TestCase;

final class InlineStylesTest extends TestCase
{
    protected function setUp(): void
    {
        InlineStyles::pull(); // registre vidé entre les tests
    }

    public function testClassCarriesTheDeclarations(): void
    {
        $class = style_class(['height' => '12px', 'background' => '#ff0000', '--chip-bg' => 'rgba(0, 0, 0, .5)']);

        $this->assertMatchesRegularExpression('/^s-[0-9a-f]{10}$/', $class);
        $this->assertSame('.' . $class . '{height:12px;background:#ff0000;--chip-bg:rgba(0, 0, 0, .5)}', InlineStyles::pull());
    }

    public function testSameDeclarationsShareOneRule(): void
    {
        $a = style_class(['color' => '#000']);
        $b = style_class(['color' => '#000']);

        $this->assertSame($a, $b);
        $this->assertSame(1, substr_count(InlineStyles::pull(), '{'));
    }

    public function testUnsafeValuesAndPropertiesAreDropped(): void
    {
        // Une couleur saisie ne doit pas pouvoir fermer la règle ni en ouvrir une autre.
        $class = style_class([
            'background' => 'red;position:fixed',
            'color'      => '#fff}body{display:none',
            'width'      => 'url(javascript:x)',
            'top:0;left' => '1px',
            'height'     => '10px',
            'margin'     => null,
            'padding'    => '',
        ]);

        $css = InlineStyles::pull();
        $this->assertStringNotContainsString('position', $css);
        $this->assertStringNotContainsString('display', $css);
        $this->assertStringNotContainsString('javascript', $css);
        $this->assertSame('.' . $class . '{height:10px}', $css);
    }

    public function testNothingToEmitGivesNoClassAndNoBlock(): void
    {
        $this->assertSame('', style_class(['color' => '<script>']));
        $this->assertSame('', inline_styles());
    }

    public function testInlineStylesEmitsANoncedBlockOnce(): void
    {
        $class = style_class(['width' => '50%']);

        $html = inline_styles();
        $this->assertSame('<style nonce="' . csp_nonce() . '">.' . $class . '{width:50%}</style>', $html);
        $this->assertSame('', inline_styles());
    }

    public function testRootVariablesKeepOnlyCustomProperties(): void
    {
        $css = InlineStyles::rootVariables('--light-primary:#ff0000;--dark-primary-light:rgba(136, 0, 0, 0.16);color:red;--x:a}b;');

        $this->assertSame(':root{--light-primary:#ff0000 !important;--dark-primary-light:rgba(136, 0, 0, 0.16) !important}', $css);
        $this->assertSame('', InlineStyles::rootVariables(''));
    }
}
