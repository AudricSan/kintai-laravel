<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core\Extension;

use kintai\Contract\Extension\NavItem;
use kintai\Core\BundleContract\Bundle;
use kintai\Core\BundleManager;
use kintai\Core\Extension\ExtensionRegistry;
use kintai\Core\Extension\Navigation;
use PHPUnit\Framework\TestCase;

/**
 * Navigation construite à partir de données (étape 2b du contrat de bundle) : entrées du Core, entrées déclarées par
 * les bundles, repli pour les bundles qui ne déclarent rien, filtres de visibilité et clés de préférences.
 */
final class NavigationTest extends TestCase
{
    /** @param array<string, list<NavItem>|null> $active slug => entrées déclarées (null = ne déclare rien) */
    private function navigation(array $active): Navigation
    {
        $bundles = [];
        foreach ($active as $slug => $declared) {
            $bundle = new class extends Bundle {
                public string $slug = '';
                public ?array $declared = null;

                public function __construct() {}

                public function getName(): string
                {
                    return $this->slug;
                }

                public function register(): void {}

                public function navigation(): array
                {
                    return $this->declared ?? [];
                }
            };
            $bundle->slug = $slug;
            $bundle->declared = $declared;
            $bundles['B' . $slug] = $bundle;
        }
        $manager = (new \ReflectionClass(BundleManager::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($manager, 'bundles'))->setValue($manager, $bundles);

        return new Navigation(new ExtensionRegistry($manager));
    }

    /** @return list<string> */
    /** Entrée déclarée par le bundle messaging (le Core n'a plus de repli pour lui). */
    private static function messaging(): array
    {
        return [new NavItem('messages', NavItem::REQUESTS, 'messages', 'admin.messages', 'employee.messages',
            icon: 'message', activePaths: ['/admin/messages', '/employee/messages'],
            storeFeature: 'messages', badge: 'unread_messages_count', order: 40, mobile: true)];
    }

    /** Entrée déclarée par timeoff (il n'a plus de repli dans le Core). */
    private static function timeoff(): array
    {
        return [new NavItem('timeoff', NavItem::REQUESTS, 'timeoff', 'admin.timeoff', 'employee.timeoff',
            icon: 'leaf', employeeLabel: 'my_timeoff', activePaths: ['/admin/timeoff', '/employee/timeoff'],
            storeFeature: 'timeoff', order: 10, mobile: true)];
    }

    /** Entrée déclarée par timeclock (il n'a plus de repli dans le Core). */
    private static function timeclock(): array
    {
        return [new NavItem('timeclocks', NavItem::PLANNING, 'timeclocks', 'admin.timeclocks', 'employee.timeclock',
            icon: 'clock', employeeLabel: 'timeclock', activePaths: ['/admin/timeclocks', '/employee/timeclock'],
            storeFeature: 'timeclock', order: 40, mobile: true)];
    }

    private static function keys(array $items): array
    {
        return array_map(fn(NavItem $i): string => $i->key, $items);
    }

    private function ctx(array $over = []): array
    {
        return $over + [
            'isManager'    => true,
            'path'         => '/admin/timeoff',
            'routeVisible' => fn(string $r): bool => true,
            'feat'         => fn(string $f): bool => true,
            'hidden'       => [],
            'routeUrl'     => fn(string $r): string => '/' . str_replace('.', '/', $r),
            'translate'    => fn(string $k): string => 'T:' . $k,
            'view'         => ['isOwner' => true, 'BASE_URL' => '', 'managed_store_ids' => null],
        ];
    }

    public function testWithoutBundlesOnlyCoreEntriesExist(): void
    {
        $keys = self::keys($this->navigation([])->items());

        $this->assertSame(['shifts', 'calendar', 'shift_types', 'users', 'stores', 'employee_report', 'audit_log', 'settings'], $keys);
    }

    /** Plus d'entrée historique : un bundle actif qui ne déclare rien n'apparaît pas dans la navigation. */
    public function testAnActiveBundleThatDeclaresNothingHasNoEntry(): void
    {
        $keys = self::keys($this->navigation(['timeoff' => null, 'shift-swap' => null])->items());

        $this->assertNotContains('timeoff', $keys);
        $this->assertNotContains('swaps', $keys);
    }

    /** Un bundle qui déclare sa navigation remplace ses entrées historiques. */
    public function testADeclaringBundleReplacesItsLegacyEntries(): void
    {
        $declared = new NavItem('leave', NavItem::REQUESTS, 'leave', 'admin.leave', 'employee.leave', order: 5);

        $keys = self::keys($this->navigation(['timeoff' => [$declared]])->items());

        $this->assertContains('leave', $keys);
        $this->assertNotContains('timeoff', $keys);
    }

    public function testACoreKeyCannotBeTakenOverByABundle(): void
    {
        $intruder = new NavItem('settings', NavItem::MAIN, 'hijack', 'evil.route', 'evil.route');

        $items = $this->navigation(['evil' => [$intruder]])->items();
        $settings = array_values(array_filter($items, fn(NavItem $i): bool => $i->key === 'settings'));

        $this->assertSame('admin.owner_settings', $settings[0]->managerRoute);
    }

    public function testVisibleAppliesHiddenFeaturePermissionAndRole(): void
    {
        $items = $this->navigation(['timeoff' => self::timeoff(), 'shift-swap' => null, 'messaging' => self::messaging()])->items();

        $visible = Navigation::visible($items, $this->ctx([
            'hidden'       => ['swaps'],
            'feat'         => fn(string $f): bool => $f !== 'messages',
            'routeVisible' => fn(string $r): bool => $r !== 'admin.shift_types',
        ]));

        $requests = array_column($visible['requests'] ?? [], 'key');
        $this->assertSame(['timeoff'], $requests, 'swaps masqué, messages désactivé pour le magasin');
        $this->assertNotContains('shift_types', array_column($visible['planning'] ?? [], 'key'), 'permission refusée');
        $this->assertSame('T:timeoff', $visible['requests'][0]['links'][0]['label']);
        $this->assertTrue($visible['requests'][0]['links'][0]['active']);
    }

    public function testEmployeeViewUsesEmployeeRoutesAndLabels(): void
    {
        $items = $this->navigation(['timeoff' => self::timeoff()])->items();

        $visible = Navigation::visible($items, $this->ctx(['isManager' => false, 'path' => '/employee/timeoff', 'view' => ['isOwner' => false]]));

        $timeoff = $visible['requests'][0];
        $this->assertSame('/employee/timeoff', $timeoff['links'][0]['href']);
        $this->assertSame('T:my_timeoff', $timeoff['links'][0]['label']);
        $this->assertArrayNotHasKey('system', array_filter($visible, fn($e) => array_column($e, 'key') === ['settings']), 'réglages réservés à l\'Owner');
    }

    public function testSettingsAreOwnerOnlyAndNeverHideable(): void
    {
        $items = $this->navigation([])->items();

        $owner = Navigation::visible($items, $this->ctx(['hidden' => ['settings']]));
        $manager = Navigation::visible($items, $this->ctx(['view' => ['isOwner' => false]]));

        $this->assertContains('settings', array_column($owner['system'], 'key'));
        $this->assertNotContains('settings', array_column($manager['system'] ?? [], 'key'));
    }

    public function testBadgeComesFromTheNamedViewVariable(): void
    {
        $items = $this->navigation(['messaging' => self::messaging()])->items();

        $visible = Navigation::visible($items, $this->ctx(['view' => ['isOwner' => true, 'unread_messages_count' => 3]]));

        $messages = array_values(array_filter($visible['requests'], fn($e) => $e['key'] === 'messages'));
        $this->assertSame(3, $messages[0]['badge']);
    }

    /** Une route de bundle introuvable ou une fonction de liens défaillante retire l'entrée, pas la page. */
    public function testAFailingEntryIsDropped(): void
    {
        $broken = new NavItem('broken', NavItem::REQUESTS, 'broken', 'admin.broken', null,
            links: fn(array $v, bool $m): array => throw new \RuntimeException('boom'));
        $items = $this->navigation(['x' => [$broken]])->items();

        $visible = Navigation::visible($items, $this->ctx(['routeUrl' => fn(string $r): string => $r === 'admin.timeoff' ? throw new \RuntimeException('route absente') : '/' . $r]));

        $this->assertNotContains('broken', array_column($visible['requests'] ?? [], 'key'));
        $this->assertArrayHasKey('planning', $visible);
    }

    public function testHideableKeysFollowTheSameRules(): void
    {
        $nav = $this->navigation(['timeoff' => self::timeoff()]);

        $keys = $nav->hideableKeys(true, fn(string $r): bool => $r !== 'admin.activity', fn(string $f): bool => true, ['isOwner' => true]);

        $this->assertContains('timeoff', $keys['requests']);
        $this->assertNotContains('audit_log', $keys['system'] ?? [], 'route non permise');
        $this->assertNotContains('settings', $keys['system'] ?? [], 'non masquable');
        $this->assertArrayNotHasKey('main', $keys, 'liens directs non masquables');
        $this->assertSame('my_timeoff', $nav->labelOf('timeoff', false));
    }

    public function testLegacyEmployeeKeysAreMigrated(): void
    {
        $this->assertSame(['shifts', 'timeclocks', 'timeoff', 'messages'], Navigation::migrateLegacyKeys(['my_planning', 'timeclock', 'my_timeoff', 'messages', 'timeoff']));
    }

    // -------------------------------------------------------------------------
    // Barre mobile (étape 2c)
    // -------------------------------------------------------------------------

    private function mobileCtx(array $over = []): array
    {
        return $over + [
            'isManager'    => true,
            'path'         => '/admin/timeoff',
            'routeVisible' => fn(string $r): bool => true,
            'feat'         => fn(string $f): bool => true,
            'routeUrl'     => fn(string $r): string => '/' . str_replace('.', '/', $r),
            'translate'    => fn(string $k): string => 'T:' . $k,
            'view'         => [],
        ];
    }

    public function testMobileDefaultsPerRole(): void
    {
        $nav = $this->navigation(['timeoff' => self::timeoff(), 'timeclock' => self::timeclock()]);

        $manager = array_column($nav->mobileTabs([], $this->mobileCtx()), 'key');
        $employee = array_column($nav->mobileTabs([], $this->mobileCtx(['isManager' => false, 'path' => '/employee'])), 'key');

        $this->assertSame(['shifts', 'users', 'timeoff'], $manager);
        $this->assertSame(['shifts', 'timeclocks', 'timeoff'], $employee);
    }

    /** Choix enregistrés sous les anciennes clés (team, requests, my_timeoff…) : toujours honorés. */
    public function testSavedLegacyMobileKeysAreHonoured(): void
    {
        $nav = $this->navigation(['timeoff' => self::timeoff(), 'messaging' => self::messaging()]);

        $tabs = $nav->mobileTabs(['team', 'requests', 'messages'], $this->mobileCtx());

        $this->assertSame(['users', 'timeoff', 'messages'], array_column($tabs, 'key'));
        $this->assertTrue($tabs[1]['active']);
        $this->assertSame('/admin/timeoff', $tabs[1]['href']);
    }

    public function testMobileTabsFallBackToDefaultsWhenFewerThanTwoAreVisible(): void
    {
        $nav = $this->navigation(['messaging' => self::messaging(), 'timeoff' => self::timeoff()]);

        $tabs = $nav->mobileTabs(['messages'], $this->mobileCtx(['feat' => fn(string $f): bool => $f !== 'messages']));

        $this->assertSame(['shifts', 'users'], array_column($tabs, 'key'));
    }

    public function testEmployeeCanPickTheirProfileButNotStaffWithoutPermission(): void
    {
        $nav = $this->navigation([]);

        $choices = $nav->mobileChoices(false, fn(string $r): bool => $r !== 'admin.users', fn(string $f): bool => true);

        $this->assertContains('my_profile', $choices);
        $this->assertNotContains('users', $choices);
        $this->assertSame('my_profile', $nav->labelOf('my_profile', false));
    }

    public function testLegacyMobileKeysAreMigrated(): void
    {
        $this->assertSame(['users', 'timeoff', 'shifts', 'timeclocks'], Navigation::migrateLegacyMobileKeys(['team', 'requests', 'my_planning', 'timeclock', 'my_timeoff']));
    }
}
