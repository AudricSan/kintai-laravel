<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\UI;

use Illuminate\Container\Container;
use kintai\UI\ViewRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Des bundles incluent des partiels du cœur par leur chemin (BASE_PATH . '/src/UI/View/_partials/…'). Avec Laravel,
 * src/ est devenu app/ et les vues sont en Blade : chaque partiel garde un relais à l'ancien chemin.
 */
final class LegacySrcPartialsTest extends TestCase
{
    private ?Container $previous = null;

    protected function setUp(): void
    {
        $this->previous = (new \ReflectionProperty(Container::class, 'instance'))->getValue();
        $container = new \kintai\Tests\Support\TestContainer();
        $container->instance(ViewRenderer::class, new ViewRenderer(dirname(__DIR__, 3) . '/app/UI/View'));
        Container::setInstance($container);
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previous);
    }

    public function testEveryPartialIsReachableFromItsOldPath(): void
    {
        $root = dirname(__DIR__, 3);
        foreach (glob($root . '/app/UI/View/_partials/*.blade.php') ?: [] as $partial) {
            $name   = basename($partial, '.blade.php');
            $legacy = $root . '/src/UI/View/_partials/' . $name . '.php';
            $this->assertFileExists($legacy);
            $this->assertStringContainsString("'_partials.{$name}'", (string) file_get_contents($legacy));
        }
    }

    public function testTheOldPathRendersThePartialWithTheCallersVariables(): void
    {
        $downloadUrl = '/x/download';
        $BASE_URL    = '';
        ob_start();
        include dirname(__DIR__, 3) . '/src/UI/View/_partials/_pdf-preview-toolbar.php';
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('pdf-preview-toolbar', $html);
        $this->assertStringContainsString('/x/download', $html);
    }
}
