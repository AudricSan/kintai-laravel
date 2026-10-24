<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core;

use kintai\Core\SessionFlash;
use PHPUnit\Framework\TestCase;

/** Messages transportés par la session à la place de l'URL, affichés une seule fois. */
final class SessionFlashTest extends TestCase
{
    protected function tearDown(): void
    {
        SessionFlash::pull();
    }

    public function testMessagesAreReturnedOnceThenCleared(): void
    {
        SessionFlash::put('danger', 'Échec de l\'installation');
        SessionFlash::put('success', 'Terminé');

        $this->assertSame([
            ['type' => 'danger', 'text' => 'Échec de l\'installation'],
            ['type' => 'success', 'text' => 'Terminé'],
        ], SessionFlash::pull());
        $this->assertSame([], SessionFlash::pull());
    }

    public function testAnUnknownTypeFallsBackToAStyledVariant(): void
    {
        SessionFlash::put('javascript', 'Texte');

        $this->assertSame('warning', SessionFlash::pull()[0]['type']);
    }

    public function testEmptyMessagesAreIgnored(): void
    {
        SessionFlash::put('danger', '   ');

        $this->assertSame([], SessionFlash::pull());
    }

    public function testMalformedSessionDataIsIgnored(): void
    {
        $_SESSION['_kintai_flash'] = ['pas un tableau', ['type' => 'danger']];

        $this->assertSame([], SessionFlash::pull());
    }
}