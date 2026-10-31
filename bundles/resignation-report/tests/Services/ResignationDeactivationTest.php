<?php

declare(strict_types=1);

namespace Tests\Bundles\ResignationReport\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use kintai\Bundles\Installed\ResignationReport\Console\DeactivateCommand;
use kintai\Bundles\Installed\ResignationReport\Models\ResignationReport;
use kintai\Bundles\Installed\ResignationReport\Services\ResignationDeactivation;
use Tests\Support\BundleMigrationHarness;
use App\Models\User;
use Illuminate\Http\Request;
use App\Services\AuditLogger;
use PHPUnit\Framework\TestCase;

/**
 * Désactivation à la date de démission (audit n°8 de Kintai, D2), sur la vraie table migrée : la tâche planifiée
 * n'applique que les rapports échus et une seule fois ; un rapport repoussé dans le futur rend l'accès.
 */
final class ResignationDeactivationTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $accounts = [];
    private ResignationDeactivation $deactivation;

    protected function setUp(): void
    {
        $capsule = kintai_test_database();
        $runner = new BundleMigrationHarness();
        $runner->runPendingFor('resignation-report', dirname(__DIR__, 2) . '/database/migrations');

        $this->accounts = [5 => ['id' => 5, 'is_active' => 1], 6 => ['id' => 6, 'is_active' => 1], 7 => ['id' => 7, 'is_active' => 1]];
        kintai_test_users(findById: fn(int $id) => $this->accounts[$id] ?? null);
        $this->deactivation = new ResignationDeactivation();
    }

    public function testTheDailyJobOnlyAppliesDueReportsAndOnlyOnce(): void
    {
        ResignationReport::put(['store_id' => 1, 'user_id' => 5, 'resignation_date' => '2026-10-10']);
        ResignationReport::put(['store_id' => 1, 'user_id' => 6, 'resignation_date' => '2026-11-30']);
        ResignationReport::put(['store_id' => 1, 'user_id' => null, 'resignation_date' => '2026-10-01']);

        $this->assertCount(1, $this->deactivation->applyDue('2026-10-15'));
        $this->assertSame(0, \App\Models\User::findById(5)['is_active']);
        $this->assertSame(1, \App\Models\User::findById(6)['is_active'], 'préavis en cours : compte encore actif');

        // Réactivé à la main : la tâche ne le désactive pas une seconde fois.
        \App\Models\User::where('id', 5)->update(['is_active' => 1]);
        $this->assertSame([], $this->deactivation->applyDue('2026-10-16'));
        $this->assertSame(1, \App\Models\User::findById(5)['is_active']);

        // Le jour venu, le second l'est.
        $this->assertCount(1, $this->deactivation->applyDue('2026-11-30'));
        $this->assertSame(0, \App\Models\User::findById(6)['is_active']);
    }

    public function testAReportPushedBackToTheFutureGivesAccessBack(): void
    {
        $report = ResignationReport::put(['store_id' => 1, 'user_id' => 7, 'resignation_date' => '2026-10-01']);
        $this->assertSame('deactivated', $this->deactivation->sync($report, '2026-10-15'));

        $moved = ResignationReport::put(['id' => (int) $report['id'], 'resignation_date' => '2026-12-31']);
        $this->assertSame('reactivated', $this->deactivation->sync($moved, '2026-10-15'));
        $this->assertSame(1, \App\Models\User::findById(7)['is_active']);
        $this->assertNull(ResignationReport::findById((int) $report['id'])['deactivated_at']);
    }

    public function testTheCommandReportsWhatItDid(): void
    {
        ResignationReport::put(['store_id' => 1, 'user_id' => 5, 'resignation_date' => date('Y-m-d', strtotime('-1 day'))]);
        app()->instance(ResignationDeactivation::class, $this->deactivation);
        app()->instance(\kintai\Contract\Services\AuditLogger::class, new AuditLogger());
        [, $output] = kintai_test_command(new DeactivateCommand());
        $body = json_decode($output, true);
        $this->assertSame(1, $body['deactivated']);
    }
}
