<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use App\Console\Commands\CleanOrphansCommand;
use App\Models\Shift;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;

/** kintai:clean-orphans : simulation par défaut, suppression des orphelins CASCADE avec --apply, anonymisation optionnelle. */
final class CleanOrphansCommandTest extends TestCase
{
    protected function setUp(): void
    {
        kintai_test_database();
        kintai_test_migrate();
        // Un dump importé contient des lignes dont le parent a disparu : les clés étrangères sont donc désactivées ici.
        DB::statement('PRAGMA foreign_keys = OFF');
        kintai_test_user(1);
        kintai_test_shifts([['id' => 1, 'user_id' => 1, 'store_id' => 1]]);
        // L'employé 99 n'existe plus (les aides de test créent les parents : insertion directe).
        DB::table('shifts')->insert(['id' => 2, 'user_id' => 99, 'store_id' => 1, 'shift_date' => '2026-01-01', 'start_time' => '09:00', 'end_time' => '17:00', 'starts_at' => '2026-01-01 09:00', 'ends_at' => '2026-01-01 17:00']);
    }

    public function testDryRunReportsOrphansWithoutTouchingThem(): void
    {
        [$code, $output] = kintai_test_command(new CleanOrphansCommand());

        $this->assertSame(0, $code);
        $this->assertStringContainsString('shifts.user_id -> users.id introuvable (1 ligne(s))', $output);
        $this->assertStringContainsString('Aucune modification écrite', $output);
        $this->assertNotNull(Shift::findById(2));
    }

    public function testApplyDeletesTheOrphansOnly(): void
    {
        [$code] = kintai_test_command(new CleanOrphansCommand(), ['--apply' => true]);

        $this->assertSame(0, $code);
        $this->assertNull(Shift::findById(2), 'shift de l\'employé disparu supprimé');
        $this->assertNotNull(Shift::findById(1), 'shift de l\'employé existant conservé');
    }

    public function testAnonymizeReplacesPersonalDataOfTheRemainingUsers(): void
    {
        kintai_test_command(new CleanOrphansCommand(), ['--apply' => true, '--anonymize' => true]);

        $user = DB::table('users')->where('id', 1)->first();
        $this->assertSame('user1@example.invalid', $user->email);
        $this->assertSame('Utilisateur 1', $user->display_name);
    }
}
