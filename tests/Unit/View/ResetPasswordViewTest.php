<?php

declare(strict_types=1);

namespace Tests\Unit\View;

use Illuminate\Contracts\View\Factory as ViewFactory;
use PHPUnit\Framework\TestCase;

/**
 * La vue échappait le message d'erreur avant de le passer à Alert, qui l'échappe lui-même : une apostrophe
 * s'affichait « &#039; ». Un seul échappement doit rester.
 */
final class ResetPasswordViewTest extends TestCase
{
    public function testTheErrorMessageIsEscapedExactlyOnce(): void
    {
        $html = (kintai_test_views(dirname(__DIR__, 3) . '/resources/views'))->make('auth.reset-password', [
            'BASE_URL' => '',
            'token'    => 't',
            'valid'    => true,
            'success'  => false,
            'error'    => "L'erreur & <b>le reste</b>",
        ])->render();

        $this->assertStringContainsString('L&#039;erreur &amp; &lt;b&gt;le reste&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('&amp;#039;', $html, 'double échappement');
        $this->assertStringNotContainsString('<b>le reste</b>', $html, 'le message doit rester échappé');
    }
}
