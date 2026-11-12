<?php

namespace App\Navigation;

use Kintai\Contracts\Navigation\NavigationItem;
use Kintai\Contracts\Navigation\NavigationRegistry;
use Kintai\Contracts\Requests\PendingRequestRegistry;

/**
 * Entrées de menu du Core.
 */
final class CoreNavigation
{
    public static function registerIn(NavigationRegistry $registry, PendingRequestRegistry $requests): void
    {
        $main = NavigationRegistry::SECTION_MAIN;
        $planning = NavigationRegistry::SECTION_PLANNING;
        $hr = NavigationRegistry::SECTION_HR;

        $registry->register(new NavigationItem('dashboard', 'Dashboard', 'dashboard', 'home', $main, 10, current: ['dashboard', 'dashboard.*']));

        $registry->register(new NavigationItem('shifts', 'Shifts', 'shifts.index', 'queue-list', $planning, 10, 'shifts.view', current: ['shifts.index', 'shifts.create', 'shifts.edit']));
        $registry->register(new NavigationItem('calendar', 'Calendar', 'shifts.calendar', 'calendar-days', $planning, 20, 'shifts.view'));
        $registry->register(new NavigationItem('timeline', 'Timeline', 'shifts.timeline', 'chart-bar', $planning, 30, 'shifts.view'));
        $registry->register(new NavigationItem('conflicts', 'Conflicts', 'shifts.conflicts', 'exclamation-triangle', $planning, 40, 'shifts.view'));
        $registry->register(new NavigationItem('shift-types', 'Shift types', 'shift-types.index', 'tag', $planning, 50, 'shifts.view', current: ['shift-types.*']));
        $registry->register(new NavigationItem('my-shifts', 'My shifts', 'my-shifts.index', 'clock', $planning, 60, current: ['my-shifts.*']));

        // Sans fournisseur enregistré (aucun bundle de demandes), l'entrée n'a rien à montrer.
        $registry->register(new NavigationItem(
            'requests', 'Requests', 'requests.index', 'inbox-stack', NavigationRegistry::SECTION_REQUESTS, 10,
            visible: fn (): bool => ! $requests->isEmpty(),
        ));

        // Ouvert à tout utilisateur connecté : chacun peut au moins exporter ses propres données.
        $registry->register(new NavigationItem('exports', 'Exports', 'exports.index', 'arrow-down-tray', $main, 20));

        // Ouverts à tout utilisateur connecté.
        $registry->register(new NavigationItem('docs', 'Documentation', 'docs.show', 'book-open', $main, 30, current: ['docs.show']));

        $registry->register(new NavigationItem('employees', 'Employees', 'employees.index', 'users', $hr, 10, 'employees.view', current: ['employees.*']));
        $registry->register(new NavigationItem('stores', 'Stores', 'stores.index', 'building-storefront', $hr, 20, 'stores.view', current: ['stores.*']));

        // Zone réservée à l'Owner : jamais masquable, comme les réglages de l'ancienne version.
        $registry->register(new NavigationItem('roles', 'Roles', 'roles.index', 'shield-check', NavigationRegistry::SECTION_SYSTEM, 10, 'manage-roles', hideable: false, current: ['roles.*']));
        // Réglages de l'instance : Owner seulement. Libellé distinct de « Settings » (réglages personnels du compte).
        $registry->register(new NavigationItem('instance-settings', 'Instance settings', 'settings.app', 'adjustments-horizontal', NavigationRegistry::SECTION_SYSTEM, 30, 'manage-settings', hideable: false, current: ['settings.app']));
        // Sauvegardes et réinitialisation des données : Owner seulement.
        $registry->register(new NavigationItem('backups', 'Backups', 'backups.index', 'archive-box', NavigationRegistry::SECTION_SYSTEM, 40, 'manage-settings', hideable: false, current: ['backups.*', 'data-reset.index']));
        // Mises à jour du Core : Owner seulement.
        $registry->register(new NavigationItem('updates', 'Updates', 'updates.index', 'arrow-path', NavigationRegistry::SECTION_SYSTEM, 42, 'manage-settings', hideable: false, current: ['updates.*']));
        // Tâches planifiées et jetons de l'appel HTTP : Owner seulement.
        $registry->register(new NavigationItem('scheduled-tasks', 'Scheduled tasks', 'scheduled-tasks.index', 'command-line', NavigationRegistry::SECTION_SYSTEM, 50, 'manage-settings', hideable: false, current: ['scheduled-tasks.*']));
        // Bundles installés, à activer ou désactiver : Owner seulement.
        $registry->register(new NavigationItem('bundles', 'Bundles', 'bundles.index', 'puzzle-piece', NavigationRegistry::SECTION_SYSTEM, 45, 'manage-settings', hideable: false, current: ['bundles.*']));
        // Journal d'audit : visible de qui a `stores.view` (un gérant ne voit que ses magasins).
        $registry->register(new NavigationItem('activity', 'Activity log', 'activity.index', 'clipboard-document-list', NavigationRegistry::SECTION_SYSTEM, 20, 'stores.view', current: ['activity.*']));

        $registry->register(new NavigationItem('notifications', 'Notifications', 'notifications.index', 'bell', NavigationRegistry::SECTION_ACCOUNT, 5, current: ['notifications.index']));

        $registry->register(new NavigationItem('report-issue', 'Report a problem', 'support.report-issue', 'flag', NavigationRegistry::SECTION_ACCOUNT, 20, current: ['support.report-issue']));

        $registry->register(new NavigationItem(
            'settings', 'Settings', 'profile.edit', 'cog', NavigationRegistry::SECTION_ACCOUNT, 10,
            hideable: false, current: ['profile.edit', 'availability.edit', 'security.edit', 'appearance.edit', 'navigation.edit', 'notification-preferences.edit', 'calendar.edit', 'data.edit'],
        ));
    }
}
