<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core\Extension;

use kintai\Core\Extension\DashboardLayout;
use PHPUnit\Framework\TestCase;

final class DashboardLayoutTest extends TestCase
{
    private const KEYS = ['kpi_counters', 'dashboard_alerts', 'shifts_today', 'pending_timeoff'];

    public function testWithoutSavedLayoutCoreWidgetsComeFirstThenBundlesWithDefaultWidths(): void
    {
        $this->assertSame(
            [
                'order'     => self::KEYS,
                'widths'    => ['kpi_counters' => 'full', 'dashboard_alerts' => 'half', 'shifts_today' => 'full', 'pending_timeoff' => 'full'],
                'heights'   => ['kpi_counters' => 1, 'dashboard_alerts' => 1, 'shifts_today' => 1, 'pending_timeoff' => 1],
                'collapsed' => [],
                'density'   => 'comfortable',
            ],
            DashboardLayout::resolve(self::KEYS, null, ['dashboard_alerts' => 'half']),
        );
    }

    public function testSavedOrderComesFirstAndNewWidgetsAreAppended(): void
    {
        // « old_widget » n'est plus proposé (bundle désactivé) ; « pending_timeoff » est apparu depuis.
        $layout = ['order' => ['shifts_today', 'old_widget', 'kpi_counters', 'dashboard_alerts'], 'widths' => ['shifts_today' => 'half']];

        $this->assertSame(['shifts_today', 'kpi_counters', 'dashboard_alerts', 'pending_timeoff'], DashboardLayout::order(self::KEYS, $layout));
        $this->assertSame('half', DashboardLayout::widths(self::KEYS, $layout)['shifts_today']);
    }

    public function testNormalizeDropsMalformedKeysDuplicatesAndWidths(): void
    {
        $this->assertSame(
            ['order' => ['kpi_counters', 'shifts_today'], 'widths' => ['shifts_today' => 'half']],
            DashboardLayout::normalize([
                'order'  => ['kpi_counters', 'Bad Key', 'kpi_counters', 42, 'shifts_today'],
                'widths' => ['shifts_today' => 'half', 'kpi_counters' => 'huge', 'Bad Key' => 'full'],
            ]),
        );
        $this->assertSame(['order' => [], 'widths' => []], DashboardLayout::normalize('nope'));
    }

    public function testUpdateKeepsHiddenWidgetsInTheirSlots(): void
    {
        // Affichés : kpi_counters, shifts_today, pending_timeoff ; dashboard_alerts est masqué (désactivé).
        $updated = DashboardLayout::update(self::KEYS, null, ['pending_timeoff', 'kpi_counters', 'shifts_today'], []);

        $this->assertSame(['pending_timeoff', 'dashboard_alerts', 'kpi_counters', 'shifts_today'], $updated['order']);
    }

    public function testUpdateKeepsWidgetsOfADisabledBundleAndMergesWidths(): void
    {
        $previous = ['order' => ['old_widget', 'kpi_counters', 'dashboard_alerts', 'shifts_today', 'pending_timeoff'], 'widths' => ['old_widget' => 'half']];

        $updated = DashboardLayout::update(
            self::KEYS,
            $previous,
            ['shifts_today', 'kpi_counters', 'dashboard_alerts', 'pending_timeoff', 'unknown_widget'],
            ['shifts_today' => 'half', 'unknown_widget' => 'half', 'kpi_counters' => 'huge'],
        );

        $this->assertSame(['old_widget', 'shifts_today', 'kpi_counters', 'dashboard_alerts', 'pending_timeoff'], $updated['order']);
        $this->assertSame(['old_widget' => 'half', 'shifts_today' => 'half'], $updated['widths']);
    }

    public function testMoveToEndPutsTheAddedWidgetLastAndKeepsTheOthers(): void
    {
        $previous = ['order' => ['shifts_today', 'old_widget', 'kpi_counters'], 'widths' => ['shifts_today' => 'half', 'kpi_counters' => 'half']];

        $this->assertSame(
            ['order' => ['shifts_today', 'old_widget', 'kpi_counters', 'pending_timeoff', 'dashboard_alerts'], 'widths' => $previous['widths']],
            DashboardLayout::moveToEnd(self::KEYS, $previous, 'dashboard_alerts'),
        );
        $this->assertSame(
            ['kpi_counters', 'shifts_today', 'pending_timeoff', 'dashboard_alerts'],
            DashboardLayout::moveToEnd(self::KEYS, null, 'dashboard_alerts')['order'],
        );
    }

    // ── Grille 12 colonnes : largeurs numériques, hauteurs, repli, densité ──────────────────────────────────────

    public function testColumnsAcceptsLegacyNamesNumbersAndFallsBackToFullWidth(): void
    {
        $this->assertSame(6, DashboardLayout::columns('half'));
        $this->assertSame(12, DashboardLayout::columns('full'));
        $this->assertSame(4, DashboardLayout::columns(4));
        $this->assertSame(8, DashboardLayout::columns('8'));
        $this->assertSame(12, DashboardLayout::columns(13));
        $this->assertSame(12, DashboardLayout::columns(0));
        $this->assertSame(12, DashboardLayout::columns('huge'));
        $this->assertSame(12, DashboardLayout::columns(null));
    }

    public function testNormalizeKeepsNumericWidthsHeightsCollapsedAndDensity(): void
    {
        $this->assertSame(
            [
                'order'     => ['kpi_counters', 'shifts_today'],
                'widths'    => ['kpi_counters' => 3, 'shifts_today' => 'half', 'pending_timeoff' => 12],
                'heights'   => ['kpi_counters' => 2, 'shifts_today' => 6],
                'collapsed' => ['shifts_today'],
                'density'   => 'compact',
            ],
            DashboardLayout::normalize([
                'order'     => ['kpi_counters', 'shifts_today'],
                'widths'    => ['kpi_counters' => 3, 'shifts_today' => 'half', 'pending_timeoff' => '12', 'x' => 5, 'dashboard_alerts' => 13, 'bad_one' => 0],
                'heights'   => ['kpi_counters' => 2, 'shifts_today' => '6', 'dashboard_alerts' => 7, 'pending_timeoff' => 0],
                'collapsed' => ['shifts_today', 'shifts_today', 'Bad Key', 5],
                'density'   => 'compact',
            ]),
        );
    }

    public function testNormalizeOmitsDefaultsSoOldLayoutsKeepTheirShape(): void
    {
        $this->assertSame(
            ['order' => ['kpi_counters'], 'widths' => ['kpi_counters' => 'full']],
            DashboardLayout::normalize(['order' => ['kpi_counters'], 'widths' => ['kpi_counters' => 'full'], 'density' => 'comfortable', 'heights' => [], 'collapsed' => []]),
        );
    }

    public function testResolveUsesSavedSizesThenDefaults(): void
    {
        $layout = DashboardLayout::normalize(['order' => self::KEYS, 'widths' => ['kpi_counters' => 3], 'heights' => ['kpi_counters' => 2], 'collapsed' => ['shifts_today', 'gone_widget'], 'density' => 'compact']);

        $resolved = DashboardLayout::resolve(self::KEYS, $layout, ['dashboard_alerts' => 4], ['dashboard_alerts' => 3]);

        $this->assertSame(3, $resolved['widths']['kpi_counters']);
        $this->assertSame(4, $resolved['widths']['dashboard_alerts']);
        $this->assertSame('full', $resolved['widths']['pending_timeoff']);
        $this->assertSame(2, $resolved['heights']['kpi_counters']);
        $this->assertSame(3, $resolved['heights']['dashboard_alerts']);
        $this->assertSame(1, $resolved['heights']['pending_timeoff']);
        $this->assertSame(['shifts_today'], $resolved['collapsed'], 'un widget qui n\'est plus proposé n\'est plus replié');
        $this->assertSame('compact', $resolved['density']);
    }

    public function testUpdateMergesHeightsCollapsedAndDensityWithoutTouchingHiddenWidgets(): void
    {
        $previous = DashboardLayout::normalize([
            'order' => self::KEYS, 'widths' => [], 'heights' => ['dashboard_alerts' => 3], 'collapsed' => ['dashboard_alerts'],
        ]);

        // dashboard_alerts n'est pas affiché (désactivé) : son repli est conservé ; les hauteurs envoyées pour un
        // widget proposé sont enregistrées, celles d'un widget inconnu sont ignorées.
        $updated = DashboardLayout::update(
            self::KEYS,
            $previous,
            ['kpi_counters', 'shifts_today', 'pending_timeoff'],
            ['kpi_counters' => 4],
            ['heights' => ['kpi_counters' => 2, 'dashboard_alerts' => 6, 'unknown_widget' => 2], 'collapsed' => ['shifts_today'], 'density' => 'compact'],
        );

        $this->assertSame(4, $updated['widths']['kpi_counters']);
        $this->assertSame(['dashboard_alerts' => 6, 'kpi_counters' => 2], $updated['heights']);
        $this->assertSame(['dashboard_alerts', 'shifts_today'], $updated['collapsed']);
        $this->assertSame('compact', $updated['density']);
    }

    public function testUpdateWithoutExtrasKeepsSavedHeightsCollapsedAndDensity(): void
    {
        $previous = DashboardLayout::normalize(['order' => self::KEYS, 'widths' => [], 'heights' => ['kpi_counters' => 2], 'collapsed' => ['kpi_counters'], 'density' => 'compact']);

        $updated = DashboardLayout::update(self::KEYS, $previous, self::KEYS, []);

        $this->assertSame(['kpi_counters' => 2], $updated['heights']);
        $this->assertSame(['kpi_counters'], $updated['collapsed']);
        $this->assertSame('compact', $updated['density']);
    }

    public function testExpandingEverythingAndComfortableDensityClearTheOptionalKeys(): void
    {
        $previous = DashboardLayout::normalize(['order' => self::KEYS, 'collapsed' => ['kpi_counters'], 'density' => 'compact']);

        $updated = DashboardLayout::update(self::KEYS, $previous, self::KEYS, [], ['collapsed' => [], 'density' => 'comfortable']);

        $this->assertArrayNotHasKey('collapsed', $updated);
        $this->assertArrayNotHasKey('density', $updated);
    }

    public function testAnAddedWidgetArrivesLastAndExpanded(): void
    {
        $previous = DashboardLayout::normalize(['order' => self::KEYS, 'widths' => ['pending_timeoff' => 4], 'collapsed' => ['pending_timeoff'], 'heights' => ['pending_timeoff' => 2]]);

        $moved = DashboardLayout::moveToEnd(self::KEYS, $previous, 'kpi_counters');

        $this->assertSame('kpi_counters', end($moved['order']));
        $this->assertSame(4, $moved['widths']['pending_timeoff']);
        $this->assertSame(['pending_timeoff'], $moved['collapsed']);

        $moved = DashboardLayout::moveToEnd(self::KEYS, $previous, 'pending_timeoff');
        $this->assertArrayNotHasKey('collapsed', $moved);
    }
}
