<?php

namespace App\Navigation;

use App\Models\User;
use App\Requests\PendingRequestRegistry;

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
            visible: fn (User $user): bool => ! $requests->isEmpty(),
        ));

        $registry->register(new NavigationItem('employees', 'Employees', 'employees.index', 'users', $hr, 10, 'employees.view', current: ['employees.*']));
        $registry->register(new NavigationItem('stores', 'Stores', 'stores.index', 'building-storefront', $hr, 20, 'stores.view', current: ['stores.*']));

        // Zone réservée à l'Owner : jamais masquable, comme les réglages de l'ancienne version.
        $registry->register(new NavigationItem('roles', 'Roles', 'roles.index', 'shield-check', NavigationRegistry::SECTION_SYSTEM, 10, 'manage-roles', hideable: false, current: ['roles.*']));
        // Journal d'audit : visible de qui a `stores.view` (un gérant ne voit que ses magasins).
        $registry->register(new NavigationItem('activity', 'Activity log', 'activity.index', 'clipboard-document-list', NavigationRegistry::SECTION_SYSTEM, 20, 'stores.view', current: ['activity.*']));

        $registry->register(new NavigationItem('notifications', 'Notifications', 'notifications.index', 'bell', NavigationRegistry::SECTION_ACCOUNT, 5, current: ['notifications.index']));

        $registry->register(new NavigationItem(
            'settings', 'Settings', 'profile.edit', 'cog', NavigationRegistry::SECTION_ACCOUNT, 10,
            hideable: false, current: ['profile.edit', 'availability.edit', 'security.edit', 'appearance.edit', 'navigation.edit', 'notification-preferences.edit'],
        ));
    }
}
