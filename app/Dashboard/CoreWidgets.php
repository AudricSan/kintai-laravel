<?php

namespace App\Dashboard;

/**
 * Widgets du Core. Les statistiques, la rentabilité et l'absentéisme appartiennent au bundle `analytics`.
 */
final class CoreWidgets
{
    public static function registerIn(DashboardWidgetRegistry $registry): void
    {
        // Tableau de bord gérant.
        $registry->register(new DashboardWidget(
            key: 'counters', label: 'Key figures', audience: DashboardWidget::MANAGER, component: 'widgets::manager.counters',
            permission: 'shifts.view', size: WidgetSize::Full, refresh: 60, order: 10,
        ));
        $registry->register(new DashboardWidget(
            key: 'pending-requests', label: 'Pending requests', audience: DashboardWidget::MANAGER, component: 'widgets::manager.pending-requests',
            size: WidgetSize::Small, refresh: 60, order: 15,
        ));
        $registry->register(new DashboardWidget(
            key: 'alerts', label: 'Alerts', audience: DashboardWidget::MANAGER, component: 'widgets::manager.alerts',
            permission: 'shifts.view', storeFeature: 'shifts', size: WidgetSize::Medium, refresh: 300, order: 20,
        ));
        $registry->register(new DashboardWidget(
            key: 'shifts-today', label: 'Today\'s shifts', audience: DashboardWidget::MANAGER, component: 'widgets::manager.shifts-today',
            permission: 'shifts.view', storeFeature: 'shifts', size: WidgetSize::Medium, refresh: 120, order: 30,
        ));

        // Tableau de bord employé.
        $registry->register(new DashboardWidget(
            key: 'my-shifts-today', label: 'My shifts today', audience: DashboardWidget::EMPLOYEE, component: 'widgets::employee.shifts-today',
            storeFeature: 'shifts', size: WidgetSize::Medium, refresh: 300, order: 10,
        ));
        $registry->register(new DashboardWidget(
            key: 'my-upcoming-shifts', label: 'My upcoming shifts', audience: DashboardWidget::EMPLOYEE, component: 'widgets::employee.upcoming',
            storeFeature: 'shifts', size: WidgetSize::Medium, order: 20,
        ));
        $registry->register(new DashboardWidget(
            key: 'my-month', label: 'My month', audience: DashboardWidget::EMPLOYEE, component: 'widgets::employee.month',
            storeFeature: 'shifts', size: WidgetSize::Full, order: 30,
        ));
    }
}
