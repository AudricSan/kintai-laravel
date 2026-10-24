<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;

/** Le service worker affiche sans son ni vibration un push que le serveur a marqué « quiet » (heures silencieuses). */
final class ServiceWorkerQuietPushTest extends TestCase
{
    public function testPushHandlerShowsQuietNotificationsSilently(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 3) . '/app/Core/Templates/sw.js.tpl');

        $this->assertStringContainsString("silent: data.quiet === '1'", $js);
        // La même option ne doit pas apparaître ailleurs : les autres notifications gardent leur son.
        $this->assertSame(1, substr_count($js, 'silent:'));
    }
}
