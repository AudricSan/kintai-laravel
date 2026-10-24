<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core\Extension;

use kintai\Contract\Provider\Attendance;
use kintai\Contract\Provider\AttendanceProvider;
use kintai\Core\BundleContract\Bundle;
use kintai\Core\BundleManager;
use kintai\Core\Extension\Attendances;
use kintai\Core\Extension\ExtensionRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Fournisseur de pointages (étape 3c du contrat de bundle, contrat 1.11) : pointages des fournisseurs déclarés
 * additionnés, isolés, au format historique ; aucun sans fournisseur.
 */
final class AttendancesTest extends TestCase
{
    /** @param array<string, list<AttendanceProvider>> $active */
    private function attendances(array $active): Attendances
    {
        $bundles = [];
        foreach ($active as $slug => $declared) {
            $bundle = new class extends Bundle {
                public string $slug = '';
                public array $declared = [];

                public function __construct() {}

                public function getName(): string
                {
                    return $this->slug;
                }

                public function register(): void {}

                public function attendanceProviders(): array
                {
                    return $this->declared;
                }
            };
            $bundle->slug = $slug;
            $bundle->declared = $declared;
            $bundles['B' . $slug] = $bundle;
        }
        $manager = (new \ReflectionClass(BundleManager::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($manager, 'bundles'))->setValue($manager, $bundles);

        return new Attendances(new ExtensionRegistry($manager));
    }

    private static function provider(array $attendance, ?\Throwable $fail = null): AttendanceProvider
    {
        return new class($attendance, $fail) implements AttendanceProvider {
            public array $calls = [];

            public function __construct(private array $attendance, private ?\Throwable $fail) {}

            public function attendance(?array $userIds, ?array $storeIds, ?string $from = null, ?string $to = null): array
            {
                if ($this->fail !== null) {
                    throw $this->fail;
                }
                $this->calls[] = [$userIds, $storeIds, $from, $to];
                return $this->attendance;
            }
        };
    }

    /** Sans fournisseur (timeclock désactivé ou désinstallé) : aucun pointage. */
    public function testNoAttendanceWithoutProvider(): void
    {
        foreach ([$this->attendances(['timeclock' => []]), new Attendances()] as $attendances) {
            $this->assertSame([], $attendances->all());
            $this->assertSame([], $attendances->forStoreBetween(1, '2026-10-01', '2026-10-14'));
            $this->assertNull($attendances->activeForUser(4));
        }
    }

    public function testDeclaredProvidersAreMergedIsolatedAndShapedLikeTheLegacyRows(): void
    {
        $clock = self::provider([
            new Attendance(10, 4, 1, '2026-10-13', '2026-10-13 09:00:00', '2026-10-13 17:00:00'),
            new Attendance(11, 4, 1, '2026-10-14', '2026-10-14 08:58:00'),
            'pas un pointage',
        ]);
        $attendances = $this->attendances([
            'timeclock' => [$clock],
            'acme'      => [self::provider([new Attendance(20, 4, 2, '2026-10-12', '2026-10-12 22:00:00')]), self::provider([], new \RuntimeException('kaput'))],
        ]);

        $this->assertSame([10, 11, 20], array_column($attendances->all(), 'id'));
        $this->assertSame(
            ['id' => 11, 'user_id' => 4, 'store_id' => 1, 'shift_date' => '2026-10-14', 'clock_in_time' => '2026-10-14 08:58:00', 'clock_out_time' => null],
            $attendances->activeForUser(4),
            'pointage ouvert le plus récent'
        );
        $attendances->forStoreBetween(1, '2026-10-01', '2026-10-14');
        $this->assertSame([null, [1], '2026-10-01', '2026-10-14'], end($clock->calls));
    }

    public function testInvalidAttendanceIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Attendance(1, 4, 1, '2026-10-14', '08:58');
    }
}
