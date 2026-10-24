<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Contract;

use kintai\Contract\Contract;
use kintai\Contract\Extension\NavItem;
use kintai\Core\BundleContract\Bundle;
use kintai\Core\BundleManager;
use kintai\Core\Extension\ExtensionRegistry;
use kintai\Core\Services\Log;
use PHPUnit\Framework\TestCase;

/** Point d'extension « navigation » (contrat 1.1) : objet NavItem et collecte par le registre du Core. */
final class NavigationExtensionTest extends TestCase
{
    protected function tearDown(): void
    {
        Log::reset();
    }

    /** @param array<string, list<NavItem>|\Closure> $byBundle slug => entrées, ou closure qui les renvoie */
    private function registry(array $byBundle): ExtensionRegistry
    {
        $bundles = [];
        foreach ($byBundle as $slug => $navigation) {
            $bundle = new class extends Bundle {
                public string $slug = '';
                public mixed $nav = [];

                public function __construct() {}

                public function getName(): string
                {
                    return $this->slug;
                }

                public function register(): void {}

                public function navigation(): array
                {
                    return $this->nav instanceof \Closure ? ($this->nav)() : $this->nav;
                }
            };
            $bundle->slug = $slug;
            $bundle->nav = $navigation;
            $bundles['Fake' . $slug] = $bundle;
        }

        $manager = (new \ReflectionClass(BundleManager::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($manager, 'bundles'))->setValue($manager, $bundles);

        return new ExtensionRegistry($manager);
    }

    private function item(string $key, string $section = NavItem::REQUESTS, int $order = 100): NavItem
    {
        return new NavItem($key, $section, 'label_' . $key, 'admin.' . $key, 'employee.' . $key, order: $order);
    }

    public function testTheContractVersionAnnouncesExtensionPoints(): void
    {
        $this->assertTrue(Contract::satisfies('^1.1'));
    }

    public function testANavItemResolvesItsRouteLabelAndActiveState(): void
    {
        $item = new NavItem('timeoff', NavItem::REQUESTS, 'timeoff', 'admin.timeoff', 'employee.timeoff',
            employeeLabel: 'my_timeoff', activePaths: ['/admin/timeoff', '/employee/timeoff']);

        $this->assertSame('admin.timeoff', $item->routeFor(true));
        $this->assertSame('employee.timeoff', $item->routeFor(false));
        $this->assertSame('timeoff', $item->labelFor(true));
        $this->assertSame('my_timeoff', $item->labelFor(false));
        $this->assertTrue($item->isActive('/employee/timeoff/new'));
        $this->assertFalse($item->isActive('/admin/shifts'));
    }

    public function testAnEntryCanProvideSeveralLinks(): void
    {
        $item = new NavItem('daily_reports', NavItem::REPORTS, 'daily_reports', 'admin.daily', 'employee.daily',
            links: fn(array $view, bool $isManager): array => array_map(
                fn(array $store): array => ['href' => '/stores/' . $store['id'], 'label' => $store['name'], 'active' => false],
                $view['stores'] ?? [],
            ) + [99 => 'ignored'],
        );

        $this->assertNull((new NavItem('x_y', NavItem::MAIN, 'x', 'a', null))->linksFor([], true));
        $this->assertSame(
            [['href' => '/stores/1', 'label' => 'Tokyo', 'active' => false]],
            $item->linksFor(['stores' => [['id' => 1, 'name' => 'Tokyo']]], false),
        );
    }

    /** @return array<string, array{\Closure}> */
    public static function invalidItems(): array
    {
        return [
            'clé invalide'      => [fn() => new NavItem('Time Off', NavItem::REQUESTS, 'x', 'a', null)],
            'section inconnue'  => [fn() => new NavItem('timeoff', 'sidebar', 'x', 'a', null)],
            'aucune route'      => [fn() => new NavItem('timeoff', NavItem::REQUESTS, 'x', null, null)],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidItems')]
    public function testInvalidItemsAreRejectedAtConstruction(\Closure $make): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $make();
    }

    public function testItemsAreCollectedFromActiveBundlesAndSorted(): void
    {
        $registry = $this->registry([
            'timeoff'  => [$this->item('timeoff', NavItem::REQUESTS, 10)],
            'timeclock' => [$this->item('timeclocks', NavItem::PLANNING, 40)],
            'messaging' => [$this->item('messages', NavItem::REQUESTS, 5)],
        ]);

        $keys = array_map(fn(NavItem $i): string => $i->key, $registry->navigation());

        $this->assertSame(['timeclocks', 'messages', 'timeoff'], $keys);
    }

    /** Un bundle défaillant perd sa contribution ; la navigation des autres reste intacte. */
    public function testAFailingOrInvalidBundleIsIsolated(): void
    {
        $registry = $this->registry([
            'broken'  => fn() => throw new \RuntimeException('table manquante'),
            'garbage' => fn() => ['pas un NavItem', $this->item('valid_one')],
            'ok'      => [$this->item('timeoff')],
        ]);

        $keys = array_map(fn(NavItem $i): string => $i->key, $registry->navigation());

        $this->assertSame(['timeoff', 'valid_one'], $keys);
    }

    public function testADuplicateKeyIsIgnored(): void
    {
        $registry = $this->registry([
            'first'  => [$this->item('messages')],
            'second' => [new NavItem('messages', NavItem::MAIN, 'other', 'admin.other', null)],
        ]);

        $nav = $registry->navigation();

        $this->assertCount(1, $nav);
        $this->assertSame('label_messages', $nav[0]->label);
    }
}
