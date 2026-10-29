<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\View;

use kintai\Core\Container;
use kintai\Core\Repositories\ShiftRepositoryInterface;
use kintai\Core\Repositories\ShiftTypeRepositoryInterface;
use kintai\Core\Repositories\StoreRepositoryInterface;
use kintai\Core\Repositories\StoreUserRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use kintai\Core\Repositories\UserShiftTypeRateRepositoryInterface;
use kintai\Core\Services\RoleAssignmentSyncService;
use kintai\Core\Services\StoreStatsService;
use kintai\UI\ViewRenderer;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Pages réelles (pas des vues factices) d'un magasin sans aucun horaire, et formulaire des retenues :
 * - /admin/stores/{id}/stats divisait par zéro (erreur 500) pour un magasin sans heures ;
 * - /admin/stores/{id}/members/{mid}/deductions appelait Form::start(), une méthode qui n'existe plus.
 */
final class EmptyStorePagesTest extends TestCase
{
    private const VIEWS = __DIR__ . '/../../../app/UI/View';

    private ViewRenderer $view;

    protected function setUp(): void
    {
        $this->view = new ViewRenderer(self::VIEWS);
        session()->put('_token', 'test-token');
        $router = kintai_test_registrar();
        $router->get('/admin/stores', ['X', 'y'], name: 'admin.stores');
    }

    protected function tearDown(): void
    {
        session()->forget('_token');
    }

    private function emptyStats(): array
    {
        $roleSync = (new \ReflectionClass(RoleAssignmentSyncService::class))->newInstanceWithoutConstructor();
        $service = new StoreStatsService(
            $this->createStub(StoreRepositoryInterface::class),
            $this->createStub(ShiftRepositoryInterface::class),
            $this->createStub(ShiftTypeRepositoryInterface::class),
            $this->createStub(StoreUserRepositoryInterface::class),
            $this->createStub(UserShiftTypeRateRepositoryInterface::class),
            $this->createStub(UserRepositoryInterface::class),
            $roleSync,
        );
        return $service->storeStats(1, 30, 0, 0);
    }

    /** La vue déclare des fonctions globales : chaque rendu de statistiques tourne dans son propre processus. */
    #[RunInSeparateProcess]
    public function testStatsPageOfAStoreWithoutShiftsRendersWithAEmptyStateInsteadOfFailing(): void
    {
        $store = ['id' => 1, 'name' => 'Vide', 'currency' => 'JPY'];
        $html = $this->view->render('analytics.store-stats', array_merge($this->emptyStats(), [
            'store' => $store, 'currency' => 'JPY', 'minShiftMin' => 0, 'maxShiftMin' => 0, 'BASE_URL' => '',
        ]));

        $this->assertStringContainsString('sstat-period-nav', $html);
        $this->assertStringContainsString('no_shift_period', $html, 'Le message « aucun shift » doit être affiché à la place des graphiques');
    }

    #[RunInSeparateProcess]
    public function testStatsPageSurvivesEmployeesWorkingZeroHours(): void
    {
        $data = array_merge($this->emptyStats(), [
            'store' => ['id' => 1, 'name' => 'Vide', 'currency' => 'JPY'], 'currency' => 'JPY',
            'minShiftMin' => 0, 'maxShiftMin' => 0, 'BASE_URL' => '',
            'n' => 2, 'hoursByUser' => [7 => 0.0, 8 => 0.0], 'usersMap' => [7 => ['id' => 7, 'last_name' => 'A', 'first_name' => 'B']],
        ]);

        $this->assertStringContainsString('sstat-progress-bar', $this->view->render('analytics.store-stats', $data));
    }

    public function testDeductionsFormRendersAPostFormWithACsrfToken(): void
    {
        $html = $this->view->render('staff.member-deductions', [
            'store' => ['id' => 1, 'name' => 'Alpha', 'currency' => 'JPY'],
            'membership' => ['id' => 9, 'store_id' => 1, 'user_id' => 3],
            'user' => ['id' => 3, 'first_name' => 'Emp', 'last_name' => 'One'],
            'deductionSettings' => [], 'deductionOverrides' => [], 'BASE_URL' => '',
        ]);

        $this->assertStringContainsString('<form method="POST" action="/admin/stores/', $html);
        $this->assertStringContainsString('/members/9/deductions', $html);
        $this->assertStringContainsString('name="_token"', $html);
        $this->assertStringContainsString('name="ded_health_insurance"', $html);
        $this->assertStringNotContainsString('</form></form>', $html);
    }
}
