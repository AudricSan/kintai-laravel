<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\View;

use kintai\UI\ViewRenderer;
use PHPUnit\Framework\TestCase;

/**
 * La vue échappait le message d'erreur avant de le passer à Alert, qui l'échappe lui-même : une apostrophe
 * s'affichait « &#039; ». Un seul échappement doit rester.
 */
final class ResetPasswordViewTest extends TestCase
{
    public function testTheErrorMessageIsEscapedExactlyOnce(): void
    {
        $html = (new ViewRenderer(dirname(__DIR__, 3) . '/app/UI/View'))->render('auth.reset-password', [
            'BASE_URL' => '',
            'token'    => 't',
            'valid'    => true,
            'success'  => false,
            'error'    => "L'erreur & <b>le reste</b>",
        ]);

        $this->assertStringContainsString('L&#039;erreur &amp; &lt;b&gt;le reste&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('&amp;#039;', $html, 'double échappement');
        $this->assertStringNotContainsString('<b>le reste</b>', $html, 'le message doit rester échappé');
    }
}
