<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core\Extension;

use kintai\Contract\Extension\PageOverlay;
use kintai\Core\BundleContract\Bundle;
use kintai\Core\BundleManager;
use kintai\Core\Extension\ExtensionRegistry;
use kintai\Core\Extension\PageOverlayHost;
use kintai\Core\Extension\PageOverlays;
use kintai\UI\ViewRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Fenêtres de page des bundles (contrat 1.27) : une seule à la fois, par priorité, jamais sur certaines pages, isolées en
 * cas de panne, et enveloppées dans un dialogue accessible.
 */
final class PageOverlaysTest extends TestCase
{
    private string $views;

    protected function setUp(): void
    {
        $this->views = sys_get_temp_dir() . '/kintai-overlay-' . bin2hex(random_bytes(4));
        mkdir($this->views, 0777, true);
        file_put_contents($this->views . '/ok.php', '<h2 id="<?= $overlay_title_id ?>">Titre <?= htmlspecialchars($name ?? "") ?></h2><span>clé:<?= $overlay_key ?></span>');
        file_put_contents($this->views . '/broken.php', '<div>avant<?php throw new \RuntimeException("vue cassée"); ?>');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->views . '/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->views);
    }

    /** @param array<string, list<PageOverlay>|\Throwable> $active slug => fenêtres déclarées */
    private function overlays(array $active): PageOverlays
    {
        $bundles = [];
        foreach ($active as $slug => $declared) {
            $bundle = new class extends Bundle {
                public string $slug = '';
                public mixed $declared = [];

                public function __construct() {}

                public function getName(): string
                {
                    return $this->slug;
                }

                public function register(): void {}

                public function pageOverlays(): array
                {
                    if ($this->declared instanceof \Throwable) {
                        throw $this->declared;
                    }
                    return $this->declared;
                }
            };
            $bundle->slug = $slug;
            $bundle->declared = $declared;
            $bundles['B' . $slug] = $bundle;
        }
        $manager = (new \ReflectionClass(BundleManager::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($manager, 'bundles'))->setValue($manager, $bundles);

        return new PageOverlays(new ExtensionRegistry($manager));
    }

    private function view(): ViewRenderer
    {
        $view = new ViewRenderer($this->views);
        $view->addNamespace('acme', $this->views);
        return $view;
    }

    private static function o(string $key, ?\Closure $visible = null, ?\Closure $data = null, string $view = 'acme::ok', int $priority = 100, ?string $permission = null, ?string $feature = null): PageOverlay
    {
        return new PageOverlay($key, $view, $visible, $data, $permission, $feature, $priority);
    }

    /** @return array<string, mixed> */
    private static function context(string $path = '/admin/dashboard'): array
    {
        return ['user' => ['id' => 1], 'now' => new \DateTimeImmutable('2026-10-21 10:00:00'), 'today' => '2026-10-21', 'path' => $path, 'store_ids' => [1], 'can' => fn() => true];
    }

    private function render(PageOverlays $overlays, string $path = '/admin/dashboard', ?callable $can = null, ?callable $feat = null): ?array
    {
        return $overlays->render(self::context($path), $this->view(), $can ?? fn() => true, $feat ?? fn() => true);
    }

    public function testTheVisibleOverlayIsRenderedWithItsDataAndTitleId(): void
    {
        $overlays = $this->overlays(['acme' => [self::o('ask', fn(array $c) => $c['today'] === '2026-10-21', fn(array $c) => ['name' => $c['user']['id'] . '<b>'])]]);

        $rendered = $this->render($overlays);

        $this->assertSame('ask', $rendered['key']);
        $this->assertStringContainsString('id="page-overlay-ask-title"', $rendered['html']);
        $this->assertStringContainsString('Titre 1&lt;b&gt;', $rendered['html']);
        $this->assertTrue($rendered['dismissible']);
        $this->assertFalse($rendered['session_only']);
    }

    public function testOnlyTheMostPrioritaryVisibleOverlayIsShown(): void
    {
        $overlays = $this->overlays([
            'zeta' => [self::o('low', priority: 200)],
            'acme' => [self::o('hidden', fn() => false, priority: 1), self::o('high', priority: 10)],
        ]);

        $this->assertSame('high', $this->render($overlays)['key']);
    }

    public function testNoOverlayWhenNothingIsVisible(): void
    {
        $this->assertNull($this->render($this->overlays(['acme' => [self::o('no', fn() => false)]])));
        $this->assertNull($this->render($this->overlays([])));
    }

    /** @return array<string, array{0: string}> */
    public static function forbiddenPaths(): array
    {
        return [
            'login' => ['/login'], 'logout' => ['/logout'], 'password reset' => ['/password/reset/abc'], 'installer' => ['/install'],
            'print' => ['/admin/shifts/print'], 'print timeline' => ['/admin/shifts/timeline/print'],
            'new shift' => ['/admin/shifts/create'], 'edit shift' => ['/admin/shifts/42/edit'], 'edit by slug' => ['/admin/shifts/abc/edit'],
        ];
    }

    #[DataProvider('forbiddenPaths')]
    public function testNeverOnLoginPrintOrShiftEditPages(string $path): void
    {
        $overlays = $this->overlays(['acme' => [self::o('ask')]]);

        $this->assertNull($this->render($overlays, $path));
        $this->assertFalse(PageOverlays::allowedOn($path));
    }

    public function testAllowedOnTheDailyPages(): void
    {
        foreach (['/', '/admin/dashboard', '/employee', '/admin/shifts', '/admin/shifts/day', '/employee/shifts/day'] as $path) {
            $this->assertTrue(PageOverlays::allowedOn($path), $path);
        }
    }

    public function testPermissionAndStoreFeatureAreRequired(): void
    {
        $overlays = $this->overlays(['acme' => [self::o('ask', permission: 'acme.view', feature: 'acme_feature')]]);

        $this->assertNull($this->render($overlays, can: fn(string $p) => false));
        $this->assertNull($this->render($overlays, feat: fn(string $f) => false));
        $this->assertSame('ask', $this->render($overlays, can: fn(string $p) => $p === 'acme.view', feat: fn(string $f) => $f === 'acme_feature')['key']);
    }

    public function testABrokenOverlayIsSkippedAndTheNextOneIsTried(): void
    {
        $overlays = $this->overlays(['acme' => [
            self::o('throws_visible', fn() => throw new \RuntimeException('boom'), priority: 1),
            self::o('throws_data', null, fn() => throw new \RuntimeException('boom'), priority: 2),
            self::o('throws_view', view: 'acme::broken', priority: 3),
            self::o('works', priority: 4),
        ]]);

        $level = ob_get_level();
        $this->assertSame('works', $this->render($overlays)['key']);
        $this->assertSame($level, ob_get_level(), 'aucun tampon laissé ouvert par la vue défaillante');
    }

    public function testABundleThatThrowsWhenDeclaringIsIgnored(): void
    {
        $overlays = $this->overlays(['bad' => new \RuntimeException('déclaration cassée'), 'acme' => [self::o('ok')]]);

        $this->assertSame('ok', $this->render($overlays)['key']);
    }

    public function testAKeyAlreadyTakenByAnotherBundleIsIgnored(): void
    {
        $overlays = $this->overlays(['a' => [self::o('same')], 'b' => [self::o('same', priority: 1)]]);

        $this->assertCount(1, $overlays->declared());
    }

    public function testOverlayDeclarationIsValidated(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PageOverlay('Bad Key', 'acme::ok');
    }

    public function testABarIsANonModalRegionAndAnUnknownPlacementIsRefused(): void
    {
        $overlays = $this->overlays(['acme' => [new PageOverlay('reminder', 'acme::ok', placement: PageOverlay::BAR)]]);
        $container = \kintai\Core\Container::getInstance();
        $container->instance(ViewRenderer::class, $this->view());
        $container->instance(\kintai\Core\Auth\PermissionService::class, (new \ReflectionClass(\kintai\Core\Auth\PermissionService::class))->newInstanceWithoutConstructor());
        $users = $this->createStub(\kintai\Core\Repositories\StoreUserRepositoryInterface::class);
        $users->method('findByUser')->willReturn([]);
        $container->instance(\kintai\Core\Repositories\StoreUserRepositoryInterface::class, $users);

        $html = PageOverlayHost::html(['id' => 7], '/admin/dashboard', null, $overlays);

        $this->assertStringContainsString('page-overlay page-overlay--bar', $html);
        $this->assertStringContainsString('role="region"', $html);
        $this->assertStringNotContainsString('aria-modal', $html, 'une barre ne bloque pas la page');
        $this->assertStringContainsString('data-placement="bar"', $html);

        $this->expectException(\InvalidArgumentException::class);
        new PageOverlay('reminder', 'acme::ok', placement: 'popup');
    }

    public function testAnEmptyViewIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PageOverlay('ok_key', '');
    }

    public function testTheHostWrapsTheOverlayInAnAccessibleDialog(): void
    {
        $overlays = $this->overlays(['acme' => [new PageOverlay('ask', 'acme::ok', null, null, null, null, 100, false, true)]]);
        $container = \kintai\Core\Container::getInstance();
        $container->instance(ViewRenderer::class, $this->view());
        $container->instance(\kintai\Core\Auth\PermissionService::class, (new \ReflectionClass(\kintai\Core\Auth\PermissionService::class))->newInstanceWithoutConstructor());
        $users = $this->createStub(\kintai\Core\Repositories\StoreUserRepositoryInterface::class);
        $users->method('findByUser')->willReturn([['store_id' => 3]]);
        $container->instance(\kintai\Core\Repositories\StoreUserRepositoryInterface::class, $users);

        $html = PageOverlayHost::html(['id' => 7], '/admin/dashboard', null, $overlays);

        $this->assertStringContainsString('role="dialog" aria-modal="true"', $html);
        $this->assertStringContainsString('aria-labelledby="page-overlay-ask-title"', $html);
        $this->assertStringContainsString('data-dismissible="0" data-once="1"', $html);
        $this->assertSame('', PageOverlayHost::html([], '/admin/dashboard', null, $overlays), 'visiteur non connecté');
        $this->assertSame('', PageOverlayHost::html(['id' => 7], '/login', null, $overlays));
    }
}
