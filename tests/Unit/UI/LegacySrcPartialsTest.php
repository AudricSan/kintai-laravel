<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\UI;

use PHPUnit\Framework\TestCase;

/**
 * Des bundles incluent des partiels du cœur par leur chemin (BASE_PATH . '/src/UI/View/_partials/…'). Avec Laravel,
 * src/ est devenu app/ : chaque partiel garde un relais à l'ancien chemin.
 */
final class LegacySrcPartialsTest extends TestCase
{
    public function testEveryPartialIsReachableFromItsOldPath(): void
    {
        $root = dirname(__DIR__, 3);
        foreach (glob($root . '/app/UI/View/_partials/*.php') ?: [] as $partial) {
            $legacy = $root . '/src/UI/View/_partials/' . basename($partial);
            $this->assertFileExists($legacy);
            $this->assertStringContainsString("/app/UI/View/_partials/" . basename($partial) . "'", (string) file_get_contents($legacy));
        }
    }

    public function testTheOldPathRendersThePartialWithTheCallersVariables(): void
    {
        $root = dirname(__DIR__, 3);
        $downloadUrl = '/x/download';
        $BASE_URL    = '';
        ob_start();
        include $root . '/src/UI/View/_partials/_pdf-preview-toolbar.php';
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('pdf-preview-toolbar', $html);
    }
}
