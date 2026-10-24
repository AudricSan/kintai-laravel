<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core\Extension;

use kintai\Contract\Provider\Absence;
use kintai\Contract\Provider\AbsenceProvider;
use kintai\Core\BundleContract\Bundle;
use kintai\Core\BundleManager;
use kintai\Core\Extension\Absences;
use kintai\Core\Extension\ExtensionRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Fournisseur d'absences (étape 3b du contrat de bundle, contrat 1.10) : absences des fournisseurs déclarés
 * additionnées, isolées et au format historique ; aucune sans fournisseur.
 */
final class AbsencesTest extends TestCase
{
    /** @param array<string, list<AbsenceProvider>> $active */
    private function absences(array $active): Absences
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

                public function absenceProviders(): array
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

        return new Absences(new ExtensionRegistry($manager));
    }

    private static function provider(array $absences, ?\Throwable $fail = null): AbsenceProvider
    {
        return new class($absences, $fail) implements AbsenceProvider {
            public ?array $lastUsers = null;
            public ?array $lastStores = null;

            public function __construct(private array $absences, private ?\Throwable $fail) {}

            public function absences(?array $userIds, ?array $storeIds): array
            {
                if ($this->fail !== null) {
                    throw $this->fail;
                }
                $this->lastUsers = $userIds;
                $this->lastStores = $storeIds;
                return $this->absences;
            }
        };
    }

    /** Sans fournisseur (timeoff désactivé ou désinstallé) : aucune absence. */
    public function testNoAbsenceWithoutProvider(): void
    {
        foreach ([$this->absences(['timeoff' => []]), new Absences()] as $absences) {
            $this->assertSame([], $absences->forUser(4));
            $this->assertSame([], $absences->forStore(1));
        }
    }

    public function testDeclaredProvidersAreMergedIsolatedAndShapedLikeTheLegacyRows(): void
    {
        $leave = self::provider([new Absence(10, 4, 1, '2026-10-05', '2026-10-06', 'vacation', Absence::APPROVED), 'pas une absence']);
        $sick = self::provider([new Absence(20, 4, 1, '2026-10-07', '2026-10-07', 'sick', Absence::PENDING)]);
        $absences = $this->absences([
            'timeoff' => [$leave],
            'acme'    => [$sick, self::provider([], new \RuntimeException('kaput'))],
        ]);

        $rows = $absences->forUser(4);

        $this->assertSame([10, 20], array_column($rows, 'id'));
        $this->assertSame(['id' => 10, 'user_id' => 4, 'store_id' => 1, 'start_date' => '2026-10-05', 'end_date' => '2026-10-06', 'type' => 'vacation', 'status' => 'approved'], $rows[0]);
        $this->assertSame([4], $leave->lastUsers);
        $this->assertNull($leave->lastStores);

        $absences->forStore(1);
        $this->assertNull($leave->lastUsers);
        $this->assertSame([1], $leave->lastStores);
    }

    public function testInvalidAbsenceIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Absence(1, 4, 1, '2026-10-05', '2026-10-01');
    }
}
