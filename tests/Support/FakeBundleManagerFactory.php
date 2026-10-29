<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Bundles\Bundle;
use App\BundleManager;

/**
 * Construit un BundleManager reflétant un ensemble donné de slugs "actifs",
 * sans passer par une vraie Application (lourde, final) — voir
 * BundleManagerTest pour le détail de la technique (newInstanceWithoutConstructor
 * + injection réflexive de $bundles). À utiliser dans tout test qui simule un
 * bundle activé/désactivé/désinstallé pour du code passant par bundle_enabled()/
 * feat_bundle() (app/Support/helpers.php), qui lisent BundleManager::isActive()
 * depuis le CHANGELOG "extraction DailyReport" — pas FeatureManager::isEnabled()
 * seul, qui ne reflète que le réglage stocké, pas la présence réelle sur le disque.
 */
final class FakeBundleManagerFactory
{
    /**
     * @param string[] $activeSlugs
     * @param array<string, list<\kintai\Contract\Extension\Action>> $actions actions déclarées, par slug (Bundle::actions())
     * @param array<string, list<\kintai\Contract\Provider\PendingRequestsProvider>> $pending fournisseurs de demandes en
     *        attente déclarés, par slug (Bundle::pendingRequestsProviders())
     * @param array<string, list<\kintai\Contract\Provider\AttendanceProvider>> $attendance fournisseurs de pointages
     *        déclarés, par slug (Bundle::attendanceProviders())
     * @param array<string, list<\kintai\Contract\Provider\AbsenceProvider>> $absence fournisseurs d'absences déclarés,
     *        par slug (Bundle::absenceProviders())
     * @param array<string, array<string, list<string>>> $permissions catégories de permissions déclarées, par slug
     *        (Bundle::permissions())
     */
    public static function withActiveSlugs(array $activeSlugs, array $actions = [], array $pending = [], array $attendance = [], array $absence = [], array $permissions = []): BundleManager
    {
        $manager = (new \ReflectionClass(BundleManager::class))->newInstanceWithoutConstructor();

        $bundles = [];
        foreach ($activeSlugs as $slug) {
            $bundles[$slug] = new class ($slug, $actions[$slug] ?? [], $pending[$slug] ?? [], $attendance[$slug] ?? [], $absence[$slug] ?? [], $permissions[$slug] ?? []) extends Bundle {
                public function __construct(
                    private readonly string $slug,
                    private readonly array $declaredActions,
                    private readonly array $declaredPending,
                    private readonly array $declaredAttendance,
                    private readonly array $declaredAbsence,
                    private readonly array $declaredPermissions,
                ) {
                }

                public function permissions(): array
                {
                    return $this->declaredPermissions;
                }

                public function absenceProviders(): array
                {
                    return $this->declaredAbsence;
                }

                public function attendanceProviders(): array
                {
                    return $this->declaredAttendance;
                }

                public function pendingRequestsProviders(): array
                {
                    return $this->declaredPending;
                }

                public function actions(): array
                {
                    return $this->declaredActions;
                }

                public function getName(): string
                {
                    return $this->slug;
                }

                public function register(): void
                {
                }
            };
        }

        $bundlesProperty = new \ReflectionProperty(BundleManager::class, 'bundles');
        $bundlesProperty->setAccessible(true);
        $bundlesProperty->setValue($manager, $bundles);

        return $manager;
    }
}
