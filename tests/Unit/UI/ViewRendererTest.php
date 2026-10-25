<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\UI;

use kintai\UI\ViewRenderer;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ViewRendererTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/kintai-views-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/pages', 0777, true);
        mkdir($this->dir . '/bundle', 0777, true);
        file_put_contents($this->dir . '/pages/hello.php', "\n<p><?= htmlspecialchars(\$name) ?> <?= \$site ?></p>");
        file_put_contents($this->dir . '/layout.php', '<main><?= $content ?></main>');
        file_put_contents($this->dir . '/pages/blade.blade.php', '<p>{{ $name }}</p>');
        file_put_contents($this->dir . '/bundle/inbox.php', '<p>inbox <?= $name ?></p>');
        file_put_contents($this->dir . '/pages/vars.php', '<?= implode(",", array_keys(get_defined_vars())) ?>');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function testAPhpViewIsRenderedAsIsWithSharedDataAndLayout(): void
    {
        $view = new ViewRenderer($this->dir);
        $view->share('site', 'Kintai');

        // La sortie est gardée telle quelle, saut de ligne de tête compris.
        $this->assertSame("<main>\n<p>A&amp;B Kintai</p></main>", $view->render('pages.hello', ['name' => 'A&B'], 'layout'));
        $this->assertSame('Kintai', $view->get('site'));
    }

    public function testTheViewSeesTheSameVariablesAsBefore(): void
    {
        $view = new ViewRenderer($this->dir);

        $this->assertStringStartsWith('_file,_data,', $view->renderPartial('pages.vars', ['x' => 1]));
    }

    public function testABladeViewIsRendered(): void
    {
        $view = new ViewRenderer($this->dir);

        $this->assertSame('<p>&lt;b&gt;</p>', $view->renderPartial('pages.blade', ['name' => '<b>']));
    }

    public function testANamespacedViewIsRendered(): void
    {
        $view = new ViewRenderer($this->dir);
        $view->addNamespace('messaging', $this->dir . '/bundle/');

        $this->assertSame('<p>inbox Aiko</p>', $view->renderPartial('messaging::inbox', ['name' => 'Aiko']));
    }

    public function testAMissingViewIsReported(): void
    {
        $view = new ViewRenderer($this->dir);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("View [pages.missing] not found at [{$this->dir}/pages/missing.php].");
        $view->renderPartial('pages.missing');
    }

    public function testAnUnknownNamespaceIsReported(): void
    {
        $view = new ViewRenderer($this->dir);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('View namespace [nope] not registered.');
        $view->renderPartial('nope::inbox');
    }

    public function testAnExceptionInAViewLeavesNoOpenBuffer(): void
    {
        file_put_contents($this->dir . '/pages/boom.php', '<p>avant<?php throw new \LogicException("boom"); ?>');
        $view  = new ViewRenderer($this->dir);
        $level = ob_get_level();

        try {
            $view->renderPartial('pages.boom');
            $this->fail('exception attendue');
        } catch (\LogicException $e) {
            $this->assertSame('boom', $e->getMessage());
        }
        $this->assertSame($level, ob_get_level());
    }
}
