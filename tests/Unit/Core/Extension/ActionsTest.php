<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core\Extension;

use kintai\Contract\Extension\Action;
use kintai\Core\BundleContract\Bundle;
use kintai\Core\BundleManager;
use kintai\Core\Extension\Actions;
use kintai\Core\Extension\ExtensionRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Liens d'action des bundles dans les pages du Core (étape 2h du contrat de bundle) : déclarations des bundles actifs
 * (Bundle::actions(), contrat 1.6), filtres.
 */
final class ActionsTest extends TestCase
{
    private mixed $scriptName = null;

    protected function setUp(): void
    {
        // base_url() se déduit de SCRIPT_NAME : application servie à la racine.
        $this->scriptName = $_SERVER['SCRIPT_NAME'] ?? null;
        $_SERVER['SCRIPT_NAME'] = '/index.php';
    }

    protected function tearDown(): void
    {
        if ($this->scriptName === null) {
            unset($_SERVER['SCRIPT_NAME']);
        } else {
            $_SERVER['SCRIPT_NAME'] = $this->scriptName;
        }
    }

    /** @param array<string, mixed> $active slug => actions déclarées (list, ou \Throwable levée) */
    private function actions(array $active): Actions
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

                public function actions(): array
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

        return new Actions(new ExtensionRegistry($manager));
    }

    /** Liens « Licencier » tels que les déclare le bundle resignation-report (il n'a plus de repli dans le Core). */
    private static function resignActions(): array
    {
        $resign = static fn(array $c): string => '/admin/stores/' . (int) $c['store_id'] . '/reports/resignation/create?user_id=' . (int) $c['user_id'];

        return [
            new Action('resign', Action::EMPLOYEE_ROW, 'resign', $resign, '✕', Action::DANGER, 'resignation_reports.create', order: 20),
            new Action('resign', Action::EMPLOYEE_STORE, 'resign', $resign, '', Action::DANGER, 'resignation_reports.create', order: 10),
        ];
    }

    /** Liens « Rapport de salaire » tels que les déclare le bundle salary-report (il n'a plus de repli dans le Core). */
    private static function salaryActions(): array
    {
        $salary = static fn(array $c): string => '/admin/stores/' . (int) $c['store_id'] . '/reports/salary/create'
            . (isset($c['user_id']) ? '?user_id=' . (int) $c['user_id'] : '');

        return [
            new Action('salary_report', Action::EMPLOYEE_ROW, 'salary_report', $salary, '💰', permission: 'payroll.generate', order: 10),
            new Action('salary_report', Action::EMPLOYEE_STORE, 'salary_report', $salary, permission: 'payroll.generate', order: 20),
            new Action('salary_report', Action::STORE_REPORT, 'salary_report', static fn(array $c): string => $salary(['store_id' => $c['store_id']]), '💰', Action::PRIMARY, order: 10),
            new Action('salary_report', Action::STORE_REPORT_ROW, 'salary_report', $salary, '💰', order: 10),
        ];
    }

    private static function keys(array $links): array
    {
        return array_column($links, 'key');
    }

    /** Déclarations des bundles actifs, ordonnées et filtrées par permission. */
    public function testDeclaredActionsOfActiveBundles(): void
    {
        $all = fn(string $p): bool => true;
        $ctx = ['user_id' => 3, 'store_id' => 5];

        $both = $this->actions(['salary-report' => self::salaryActions(), 'resignation-report' => self::resignActions()]);
        $links = $both->links(Action::EMPLOYEE_ROW, $ctx, $all);
        $this->assertSame(['salary_report', 'resign'], self::keys($links));
        $this->assertSame('/admin/stores/5/reports/salary/create?user_id=3', $links[0]['href']);
        $this->assertSame('danger', $links[1]['style']);
        $this->assertSame(['resign', 'salary_report'], self::keys($both->links(Action::EMPLOYEE_STORE, $ctx + ['store_name' => 'S'], $all)));
        $this->assertSame('/admin/stores/5/reports/salary/create', $both->links(Action::STORE_REPORT, ['store_id' => 5], $all)[0]['href']);

        $this->assertSame([], $this->actions([])->links(Action::EMPLOYEE_ROW, $ctx, $all), 'aucun bundle actif : aucun lien');
        $this->assertSame(['salary_report'], self::keys($both->links(Action::EMPLOYEE_ROW, $ctx, fn(string $p): bool => $p === 'payroll.generate')));
    }

    /** Plus de repli : un bundle actif qui ne déclare rien n'ajoute aucun lien (le Core n'en connaît aucun). */
    public function testNoLegacyActions(): void
    {
        $this->assertSame([], $this->actions(['shift-swap' => []])->inSlot(Action::SHIFTS_EMPLOYEE));
        $this->assertSame([], $this->actions([])->inSlot(Action::SHIFTS_EMPLOYEE));
        $this->assertSame([], $this->actions(['shift-swap' => [new Action('other', Action::EMPLOYEE_ROW, 'o', fn(): string => '/o')]])->inSlot(Action::SHIFTS_EMPLOYEE));
    }

    public function testDeclaringBundleActionsAreFilteredAndDeduplicated(): void
    {
        $actions = $this->actions([
            'salary-report' => [
                new Action('payslip', Action::EMPLOYEE_ROW, 'payslip', fn(array $c): string => '/payslip/' . $c['user_id'], '🧾', order: 5),
                new Action('hidden', Action::EMPLOYEE_ROW, 'hidden', fn(): ?string => null),
                new Action('broken', Action::EMPLOYEE_ROW, 'broken', fn(): string => throw new \RuntimeException('kaput')),
                new Action('needs_feature', Action::EMPLOYEE_ROW, 'f', fn(): string => '/f', storeFeature: 'payslips'),
            ],
            'resignation-report' => self::resignActions(),
            'acme' => [new Action('resign', Action::EMPLOYEE_ROW, 'stolen', fn(): string => '/stolen', order: 99), 'pas une action'],
            'boom' => new \RuntimeException('kaput'),
        ]);

        $links = $actions->links(Action::EMPLOYEE_ROW, ['user_id' => 3, 'store_id' => 5], fn(): bool => true, fn(?string $f): bool => false);

        $this->assertSame(['payslip', 'resign'], self::keys($links));
        $this->assertSame('/payslip/3', $links[0]['href']);
        $this->assertSame('/admin/stores/5/reports/resignation/create?user_id=3', $links[1]['href'], 'clé déjà prise : la première gagne');
    }

    public function testInvalidActionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Action('ok_key', 'nowhere', 'label', fn(): string => '/');
    }
}
