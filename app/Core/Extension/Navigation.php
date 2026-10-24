<?php

declare(strict_types=1);

namespace kintai\Core\Extension;

use kintai\Contract\Extension\NavItem;
use kintai\Core\Container;

/**
 * Navigation de la barre du haut et des préférences de navigation, construite à partir de données :
 * - les entrées du Core (planning, personnel, rapports du Core, système) ;
 * - les entrées déclarées par les bundles actifs (point d'extension Bundle::navigation(), contrat 1.1).
 *
 * Une même clé sert à la barre du haut et aux préférences (« masquer cette entrée ») pour tous les rôles.
 */
final class Navigation
{
    /** Entrées que l'utilisateur ne peut pas masquer. */
    public const NOT_HIDEABLE = ['settings'];

    /**
     * Anciennes clés de préférences employé (onglet « navigation » du profil) : elles n'ont jamais correspondu aux
     * clés lues par la barre du haut, donc les masquer n'avait aucun effet. Remplacées par les clés uniques.
     */
    public const LEGACY_EMPLOYEE_KEYS = ['my_planning' => 'shifts', 'timeclock' => 'timeclocks', 'my_timeoff' => 'timeoff'];

    /** Anciennes clés de la barre mobile (gérant : team, requests ; employé : comme ci-dessus). */
    public const LEGACY_MOBILE_KEYS = self::LEGACY_EMPLOYEE_KEYS + ['team' => 'users', 'requests' => 'timeoff'];

    /** Barre mobile par défaut (2 à 4 entrées choisies par l'utilisateur, sinon celles-ci). */
    public const MOBILE_DEFAULT_MANAGER = ['shifts', 'users', 'timeoff'];
    public const MOBILE_DEFAULT_EMPLOYEE = ['shifts', 'timeclocks', 'timeoff'];

    public function __construct(private readonly ?ExtensionRegistry $registry = null) {}

    /** Instance de la requête courante (registre des extensions), ou Core seul si indisponible. */
    public static function current(): self
    {
        try {
            $container = Container::getInstance();
            return new self($container->has(ExtensionRegistry::class) ? $container->make(ExtensionRegistry::class) : null);
        } catch (\Throwable) {
            return new self();
        }
    }

    /**
     * Toutes les entrées : Core, puis bundles actifs déclarants ; triées par section puis par ordre. Une clé déjà
     * présente n'est pas reprise.
     *
     * @return list<NavItem>
     */
    public function items(): array
    {
        $items = self::coreItems();
        array_push($items, ...($this->registry?->navigation() ?? []));

        $unique = [];
        foreach ($items as $item) {
            $unique[$item->key] ??= $item;
        }
        $items = array_values($unique);
        usort($items, static fn(NavItem $a, NavItem $b): int =>
            [array_search($a->section, NavItem::SECTIONS, true), $a->order, $a->key]
            <=> [array_search($b->section, NavItem::SECTIONS, true), $b->order, $b->key]);

        return $items;
    }

    /**
     * Entrées visibles pour la requête courante, par section, prêtes à afficher.
     *
     * @param array{
     *     isManager: bool, path: string, routeVisible: \Closure, feat: \Closure, hidden: list<string>,
     *     routeUrl: \Closure, translate: \Closure, view: array<string, mixed>
     * } $ctx
     * @return array<string, list<array{key: string, icon: string, links: list<array{href: string, label: string, active: bool}>, badge: int}>>
     */
    public static function visible(array $items, array $ctx): array
    {
        // Variables fournies aux fonctions de liens (contrat : route_visible, route_url, path, isOwner, BASE_URL, et celles de la vue).
        $view = ['route_visible' => $ctx['routeVisible'], 'route_url' => $ctx['routeUrl'], 'path' => $ctx['path']] + $ctx['view'];

        $sections = [];
        foreach ($items as $item) {
            if (in_array($item->key, $ctx['hidden'], true) && !in_array($item->key, self::NOT_HIDEABLE, true)) {
                continue;
            }
            if ($item->storeFeature !== null && !($ctx['feat'])($item->storeFeature)) {
                continue;
            }
            $route = $item->routeFor($ctx['isManager']);
            if ($route === null) {
                continue;
            }

            try {
                $links = $item->linksFor($view, $ctx['isManager']);
                if ($links === null) {
                    if (!($ctx['routeVisible'])($route)) {
                        continue;
                    }
                    $links = [[
                        'href'   => ($ctx['routeUrl'])($route),
                        'label'  => ($ctx['translate'])($item->labelFor($ctx['isManager'])),
                        'active' => $item->isActive($ctx['path']),
                    ]];
                }
            } catch (\Throwable) {
                // Route d'un bundle introuvable, fonction de liens défaillante : l'entrée disparaît, la page reste.
                continue;
            }
            if ($links === []) {
                continue;
            }

            $sections[$item->section][] = [
                'key'   => $item->key,
                'icon'  => $item->icon,
                'links' => $links,
                'badge' => $item->badge !== null ? (int) ($view[$item->badge] ?? 0) : 0,
            ];
        }
        return $sections;
    }

    /**
     * Clés masquables par section, pour la page de préférences du rôle donné.
     *
     * @param \Closure(string): bool      $routeVisible
     * @param \Closure(string): bool      $feat
     * @param array<string, mixed>        $view  Variables de la page, pour les entrées à liens multiples
     * @return array<string, list<string>>
     */
    public function hideableKeys(bool $isManager, \Closure $routeVisible, \Closure $feat, array $view = []): array
    {
        $view = ['route_visible' => $routeVisible, 'route_url' => static fn(string $r): string => route_url($r), 'path' => ''] + $view;
        $sections = [];
        foreach ($this->items() as $item) {
            $route = $item->routeFor($isManager);
            // Les liens directs (section MAIN) et les entrées non masquables ne figurent pas dans les préférences.
            if ($route === null || $item->section === NavItem::MAIN || in_array($item->key, self::NOT_HIDEABLE, true)) {
                continue;
            }
            if ($item->storeFeature !== null && !$feat($item->storeFeature)) {
                continue;
            }
            if ($item->links === null && !$routeVisible($route)) {
                continue;
            }
            if ($item->links !== null) {
                try {
                    if ($item->linksFor($view, $isManager) === []) {
                        continue;
                    }
                } catch (\Throwable) {
                    continue;
                }
            }
            $sections[$item->section][] = $item->key;
        }
        return $sections;
    }

    /**
     * Libellé (clé de traduction) d'une entrée pour la page de préférences.
     */
    public function labelOf(string $key, bool $isManager): string
    {
        foreach ([...$this->items(), ...self::mobileOnlyItems()] as $item) {
            if ($item->key === $key) {
                return $item->labelFor($isManager);
            }
        }
        return $key;
    }

    /**
     * Entrées que l'utilisateur peut placer dans la barre mobile : celles marquées « mobile » qui existent pour son
     * rôle, plus « Mon profil » côté employé (propre à la barre mobile).
     *
     * @return list<NavItem>
     */
    public function mobileItems(bool $isManager): array
    {
        $items = array_filter($this->items(), static fn(NavItem $i): bool => $i->mobile);
        array_push($items, ...self::mobileOnlyItems());
        return array_values(array_filter($items, static fn(NavItem $i): bool => $i->routeFor($isManager) !== null));
    }

    /** @return list<string> */
    public function mobileKeys(bool $isManager): array
    {
        return array_map(static fn(NavItem $i): string => $i->key, $this->mobileItems($isManager));
    }

    /**
     * Choix proposés dans les préférences : entrées mobiles dont la route est permise et la fonctionnalité active.
     *
     * @return list<string>
     */
    public function mobileChoices(bool $isManager, \Closure $routeVisible, \Closure $feat): array
    {
        $keys = [];
        foreach ($this->mobileItems($isManager) as $item) {
            if ($item->storeFeature !== null && !$feat($item->storeFeature)) {
                continue;
            }
            if ($item->links === null && !$routeVisible((string) $item->routeFor($isManager))) {
                continue;
            }
            $keys[] = $item->key;
        }
        return $keys;
    }

    /**
     * Onglets de la barre mobile à afficher : les entrées choisies (ou par défaut) visibles, complétées par les
     * entrées par défaut si moins de deux le sont.
     *
     * @param list<string> $saved
     * @param array{isManager: bool, path: string, routeVisible: \Closure, feat: \Closure, routeUrl: \Closure, translate: \Closure, view: array<string, mixed>} $ctx
     * @return list<array{key: string, icon: string, href: string, label: string, active: bool}>
     */
    public function mobileTabs(array $saved, array $ctx): array
    {
        $default = $ctx['isManager'] ? self::MOBILE_DEFAULT_MANAGER : self::MOBILE_DEFAULT_EMPLOYEE;
        $byKey = [];
        foreach ($this->mobileItems($ctx['isManager']) as $item) {
            $byKey[$item->key] = $item;
        }
        $view = ['route_visible' => $ctx['routeVisible'], 'route_url' => $ctx['routeUrl'], 'path' => $ctx['path']] + $ctx['view'];

        $tab = static function (NavItem $item) use ($ctx, $view): ?array {
            if ($item->storeFeature !== null && !($ctx['feat'])($item->storeFeature)) {
                return null;
            }
            try {
                $links = $item->linksFor($view, $ctx['isManager']);
                if ($links === null) {
                    $route = (string) $item->routeFor($ctx['isManager']);
                    if (!($ctx['routeVisible'])($route)) {
                        return null;
                    }
                    $links = [['href' => ($ctx['routeUrl'])($route), 'label' => ($ctx['translate'])($item->labelFor($ctx['isManager'])), 'active' => $item->isActive($ctx['path'])]];
                }
            } catch (\Throwable) {
                return null;
            }
            // Un seul onglet par entrée : le premier lien (ex. rapports journaliers d'un seul magasin).
            return $links === [] ? null : ['key' => $item->key, 'icon' => $item->icon] + $links[0];
        };

        $tabs = [];
        foreach (self::migrateLegacyMobileKeys($saved !== [] ? $saved : $default) as $key) {
            if (isset($byKey[$key]) && ($t = $tab($byKey[$key])) !== null) {
                $tabs[$key] = $t;
            }
        }
        foreach ($default as $key) {
            if (count($tabs) >= 2) {
                break;
            }
            if (!isset($tabs[$key]) && isset($byKey[$key]) && ($t = $tab($byKey[$key])) !== null) {
                $tabs[$key] = $t;
            }
        }
        return array_values($tabs);
    }

    /** @param list<string> $keys @return list<string> */
    public static function migrateLegacyMobileKeys(array $keys): array
    {
        return array_values(array_unique(array_map(
            static fn(string $k): string => self::LEGACY_MOBILE_KEYS[$k] ?? $k,
            array_filter($keys, 'is_string'),
        )));
    }

    /** @param list<string> $hidden @return list<string> */
    public static function migrateLegacyKeys(array $hidden): array
    {
        return array_values(array_unique(array_map(
            static fn(string $k): string => self::LEGACY_EMPLOYEE_KEYS[$k] ?? $k,
            array_filter($hidden, 'is_string'),
        )));
    }

    /** Entrées propres à la barre mobile (absentes de la barre du haut). @return list<NavItem> */
    public static function mobileOnlyItems(): array
    {
        return [
            new NavItem('my_profile', NavItem::MAIN, 'my_profile', null, 'profile', icon: 'person', activePaths: ['/profile'], mobile: true),
        ];
    }

    /** @return list<NavItem> */
    public static function coreItems(): array
    {
        $settingsPaths = ['/admin/owner-settings', '/admin/backup', '/admin/update', '/admin/languages', '/admin/bundles', '/admin/license'];

        return [
            new NavItem('shifts', NavItem::PLANNING, 'shifts', 'admin.shifts.timeline', 'employee.shifts.day',
                icon: 'calendar', employeeLabel: 'my_planning', activePaths: ['/admin/shifts', '/employee/shifts'],
                storeFeature: 'shifts', order: 10, mobile: true),
            new NavItem('calendar', NavItem::PLANNING, 'calendar', 'admin.shifts.calendar', 'admin.shifts.calendar',
                icon: 'calendar', activePaths: ['/admin/shifts/calendar'], order: 20),
            new NavItem('shift_types', NavItem::PLANNING, 'shift_types', 'admin.shift_types', 'admin.shift_types',
                icon: 'tag', activePaths: ['/admin/shift-types'], order: 30),
            new NavItem('users', NavItem::HR, 'staff', 'admin.users', 'admin.users',
                icon: 'users', activePaths: ['/admin/users'], order: 10, mobile: true),
            new NavItem('stores', NavItem::HR, 'stores', 'admin.stores', 'admin.stores',
                icon: 'store', activePaths: ['/admin/stores'], order: 20),
            // Lien direct vers le rapport du magasin quand l'utilisateur n'en gère qu'un, sinon vers la liste.
            new NavItem('employee_report', NavItem::REPORTS, 'employee_report', 'admin.stores.employee_report', 'admin.stores.employee_report',
                icon: 'chart', order: 20,
                links: static function (array $view, bool $isManager): array {
                    if (!($view['route_visible'])('admin.stores.employee_report')) {
                        return [];
                    }
                    $managed = $view['managed_store_ids'] ?? null;
                    $base = (string) ($view['BASE_URL'] ?? '');
                    $href = is_array($managed) && count($managed) === 1
                        ? $base . '/admin/stores/' . store_segment((int) $managed[0]) . '/employee-report'
                        : $base . '/admin/stores';
                    return [['href' => $href, 'label' => __('employee_report'), 'active' => str_contains((string) ($view['path'] ?? ''), '/employee-report')]];
                }),
            new NavItem('audit_log', NavItem::SYSTEM, 'activity_log', 'admin.activity', 'admin.activity',
                icon: 'history', activePaths: ['/admin/activity'], order: 10),
            // Réglages de l'instance : Owner uniquement (OwnerOnlyMiddleware, pas une permission délégable).
            new NavItem('settings', NavItem::SYSTEM, 'settings', 'admin.owner_settings', 'admin.owner_settings',
                icon: 'gear', activePaths: $settingsPaths, order: 20,
                links: static function (array $view, bool $isManager) use ($settingsPaths): array {
                    if (empty($view['isOwner'])) {
                        return [];
                    }
                    $path = (string) ($view['path'] ?? '');
                    $active = array_filter($settingsPaths, static fn(string $p): bool => str_starts_with($path, $p)) !== [];
                    return [['href' => ($view['route_url'])('admin.owner_settings'), 'label' => __('settings'), 'active' => $active]];
                }),
        ];
    }
}
