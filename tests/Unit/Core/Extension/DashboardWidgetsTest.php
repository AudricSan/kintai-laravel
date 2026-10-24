<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core\Extension;

use kintai\Contract\Extension\DashboardWidget;
use kintai\Core\BundleContract\Bundle;
use kintai\Core\BundleManager;
use kintai\Core\Extension\DashboardWidgets;
use kintai\Core\Extension\ExtensionRegistry;
use kintai\UI\ViewRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Widgets des tableaux de bord construits à partir de données (étape 2f du contrat de bundle) : widgets du Core,
 * déclarations des bundles actifs (Bundle::dashboardWidgets(), contrat 1.4), remplacement des widgets historiques,
 * rendu isolé et préférences.
 */
final class DashboardWidgetsTest extends TestCase
{
    private const CORE = ['kpi_counters', 'shifts_today', 'pending_timeoff', 'pending_swaps'];

    private string $views;

    protected function setUp(): void
    {
        $this->views = sys_get_temp_dir() . '/kintai-dw-' . bin2hex(random_bytes(4));
        mkdir($this->views . '/widgets', 0777, true);
        file_put_contents($this->views . '/widgets/ok.php', '<div class="w"><?= htmlspecialchars($title) ?>|<?= $widget_key ?></div>');
        file_put_contents($this->views . '/widgets/broken.php', '<div>avant<?php throw new \RuntimeException("vue cassée"); ?>');
    }

    protected function tearDown(): void
    {
        foreach (['widgets/ok.php', 'widgets/broken.php'] as $f) {
            @unlink($this->views . '/' . $f);
        }
        @rmdir($this->views . '/widgets');
        @rmdir($this->views);
    }

    /** @param array<string, mixed> $active slug => widgets déclarés (list, ou \Throwable levée) */
    private function widgets(array $active): DashboardWidgets
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

                public function dashboardWidgets(): array
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

        return new DashboardWidgets(new ExtensionRegistry($manager));
    }

    private static function w(string $key, string $audience = DashboardWidget::MANAGER, string $view = 'acme::widgets.ok', ?\Closure $data = null, ?string $permission = null, ?string $feature = null, int $order = 100): DashboardWidget
    {
        return new DashboardWidget($key, $audience, 'label_' . $key, $view, $data, $permission, $feature, $order);
    }

    private function renderer(): ViewRenderer
    {
        $view = new ViewRenderer($this->views);
        $view->addNamespace('acme', $this->views);
        return $view;
    }

    public function testCoreOnlyWithoutDeclarations(): void
    {
        $dash = $this->widgets(['timeoff' => []]);

        $this->assertSame(self::CORE, $dash->keys(DashboardWidget::MANAGER, self::CORE));
        $this->assertSame(self::CORE, (new DashboardWidgets())->keys(DashboardWidget::MANAGER, self::CORE));
    }

    /**
     * Les widgets déclarés s'ajoutent après ceux du Core ; un bundle ne peut reprendre ni la clé d'un widget du Core,
     * ni celle déjà prise par un autre bundle.
     */
    public function testDeclaredWidgetsComeAfterTheCoreOnesWithoutTakingTheirKeys(): void
    {
        $dash = $this->widgets([
            'timeoff'  => [self::w('pending_timeoff', order: 20), self::w('timeoff_calendar', order: 10)],
            'acme'     => [self::w('shifts_today'), self::w('timeoff_calendar'), self::w('acme_board', DashboardWidget::EMPLOYEE)],
            'notebook' => [self::w('my_notes', DashboardWidget::EMPLOYEE)],
        ]);

        $this->assertSame(
            ['kpi_counters', 'shifts_today', 'pending_timeoff', 'pending_swaps', 'timeoff_calendar'],
            $dash->keys(DashboardWidget::MANAGER, self::CORE)
        );
        $this->assertSame(['acme_board', 'my_notes'], array_map(
            fn(DashboardWidget $w): string => $w->key,
            $dash->declared(DashboardWidget::EMPLOYEE, ['timeclock', 'team_notes'])
        ));
    }

    public function testRenderOnlyEnabledVisibleWidgetsAndIsolatesFailures(): void
    {
        $dash = $this->widgets([
            'acme' => [
                self::w('a_ok', data: fn(array $ctx): array => ['title' => 'Bonjour ' . $ctx['today']], order: 1),
                self::w('b_disabled', data: fn(): array => ['title' => 'x'], order: 2),
                self::w('c_no_perm', data: fn(): array => ['title' => 'x'], permission: 'acme.view', order: 3),
                self::w('d_no_feature', data: fn(): array => ['title' => 'x'], feature: 'acme', order: 4),
                self::w('e_data_throws', data: fn(): array => throw new \RuntimeException('base absente'), order: 5),
                self::w('f_view_throws', view: 'acme::widgets.broken', order: 6),
                self::w('g_bad_data', data: fn() => 'pas un tableau', order: 7),
                self::w('h_missing_view', view: 'acme::widgets.nope', order: 8),
            ],
        ]);
        $level = ob_get_level();

        $html = $dash->render(
            DashboardWidget::MANAGER,
            self::CORE,
            ['a_ok', 'c_no_perm', 'd_no_feature', 'e_data_throws', 'f_view_throws', 'g_bad_data', 'h_missing_view'],
            ['today' => '2026-10-14'],
            $this->renderer(),
            fn(string $p): bool => false,
            fn(?string $f): bool => false,
        );

        $this->assertSame([['key' => 'a_ok', 'html' => '<div class="w">Bonjour 2026-10-14|a_ok</div>']], $html);
        $this->assertSame($level, ob_get_level(), 'tampon de sortie d\'une vue en échec refermé');
    }

    public function testMetaForTheCustomizePanel(): void
    {
        $dash = $this->widgets(['acme' => [self::w('acme_board', permission: 'acme.view', feature: 'acme')]]);

        $meta = $dash->meta(DashboardWidget::MANAGER, self::CORE);

        $this->assertSame(['acme_board'], array_keys($meta['labels']));
        $this->assertSame(['acme_board' => 'acme.view'], $meta['permissions']);
        $this->assertSame(['acme_board' => 'acme'], $meta['features']);
    }

    public function testBrokenBundleIsIgnored(): void
    {
        $dash = $this->widgets(['boom' => new \RuntimeException('kaput'), 'acme' => [self::w('acme_board'), 'pas un widget']]);

        $this->assertSame([...self::CORE, 'acme_board'], $dash->keys(DashboardWidget::MANAGER, self::CORE));
    }

    public function testToggleAddsAtTheEndAndKeepsWidgetsOfDisabledBundles(): void
    {
        // « gone_widget » vient d'un bundle désactivé : non proposé, mais conservé ; « Bad Key » est écarté.
        $this->assertSame(
            ['gone_widget', 'kpi_counters', 'acme_board'],
            DashboardWidgets::toggle(['kpi_counters', 'shifts_today', 'acme_board'], ['acme_board', 'gone_widget', 'Bad Key', 'kpi_counters'], 'acme_board', true),
        );
        $this->assertSame(
            ['acme_board', 'gone_widget'],
            DashboardWidgets::toggle(['kpi_counters', 'acme_board'], ['kpi_counters', 'acme_board', 'gone_widget'], 'kpi_counters', false),
        );
    }

    public function testToggleStartsFromTheDefaultSelectionWithoutPreferences(): void
    {
        $this->assertSame(['kpi_counters', 'acme_board'], DashboardWidgets::toggle(['kpi_counters', 'shifts_today', 'acme_board'], null, 'shifts_today', false));
        // Déjà affiché par défaut : l'ajout le replace simplement en dernier, sans doublon.
        $this->assertSame(['shifts_today', 'kpi_counters'], DashboardWidgets::toggle(['kpi_counters', 'shifts_today'], null, 'kpi_counters', true));
    }

    public function testInvalidWidgetIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DashboardWidget('ok_key', 'admin', 'label', 'acme::w');
    }

    // ── Tailles déclarées par les bundles (contrat 1.26) ────────────────────────────────────────────────────────

    public function testSizesExposeDefaultsLimitsAndRefreshOfDeclaredWidgets(): void
    {
        $widgets = $this->widgets(['acme' => [
            new DashboardWidget('acme_small', DashboardWidget::MANAGER, 'k', 'v', defaultWidth: 4, defaultHeight: 2, minWidth: 3, maxWidth: 6, refreshInterval: 60),
            new DashboardWidget('acme_plain', DashboardWidget::MANAGER, 'k', 'v'),
            new DashboardWidget('acme_employee', DashboardWidget::EMPLOYEE, 'k', 'v', defaultWidth: 6),
        ]]);

        $sizes = $widgets->sizes(DashboardWidget::MANAGER, self::CORE);

        $this->assertSame(['acme_small' => 4], $sizes['widths'], 'sans largeur par défaut, la pleine largeur d\'avant s\'applique');
        $this->assertSame(['acme_small' => 2], $sizes['heights']);
        $this->assertSame(['acme_plain' => [1, 12], 'acme_small' => [3, 6]], $sizes['limits']);
        $this->assertSame(['acme_small' => 60], $sizes['refresh']);
    }

    public function testTheDefaultsKeepTheOldBehaviour(): void
    {
        $widget = new DashboardWidget('acme_plain', DashboardWidget::MANAGER, 'k', 'v');

        $this->assertNull($widget->defaultWidth);
        $this->assertSame(1, $widget->defaultHeight);
        $this->assertSame([1, 12], [$widget->minWidth, $widget->maxWidth]);
        $this->assertSame(0, $widget->refreshInterval);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function invalidSizes(): array
    {
        return [
            'min above max'          => [['minWidth' => 8, 'maxWidth' => 4]],
            'max above 12'           => [['maxWidth' => 13]],
            'min below 1'            => [['minWidth' => 0]],
            'default under the min'  => [['minWidth' => 4, 'defaultWidth' => 3]],
            'default over the max'   => [['maxWidth' => 6, 'defaultWidth' => 7]],
            'height above 6'         => [['defaultHeight' => 7]],
            'height below 1'         => [['defaultHeight' => 0]],
            'refresh under 15 s'     => [['refreshInterval' => 5]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidSizes')]
    public function testInvalidSizesAreRejected(array $args): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new DashboardWidget('acme_bad', DashboardWidget::MANAGER, 'k', 'v', ...$args);
    }
}
