<?php

declare(strict_types=1);

namespace kintai\Tests\Support;

use kintai\Contract\Provider\Absence;
use kintai\Contract\Provider\AbsenceProvider;
use kintai\Core\BundleManager;
use kintai\Core\Container;
use kintai\Core\Extension\ExtensionRegistry;

/**
 * Fournisseur d'absences factice, à la place de celui que déclare timeoff : pour tester les pages et services du Core
 * qui lisent les congés (planning, conflits, export iCal, statistiques). Les lignes ont la forme de la table
 * timeoff_requests.
 */
final class FakeAbsenceProvider implements AbsenceProvider
{
    /** @param list<array<string, mixed>> $rows */
    public function __construct(private readonly array $rows) {}

    /**
     * Déclare ces congés pour la requête courante : bundle « timeoff » actif dans le conteneur, avec ce fournisseur.
     *
     * @param list<array<string, mixed>> $rows
     */
    public static function register(array $rows): void
    {
        $manager = FakeBundleManagerFactory::withActiveSlugs(['timeoff'], absence: ['timeoff' => [new self($rows)]]);
        Container::getInstance()->instance(BundleManager::class, $manager);
        Container::getInstance()->instance(ExtensionRegistry::class, new ExtensionRegistry($manager));
    }

    public function absences(?array $userIds, ?array $storeIds): array
    {
        $absences = [];
        foreach ($this->rows as $r) {
            if (($userIds !== null && !in_array((int) ($r['user_id'] ?? 0), $userIds, true))
                || ($storeIds !== null && !in_array((int) ($r['store_id'] ?? 0), $storeIds, true))) {
                continue;
            }
            $absences[] = new Absence((int) ($r['id'] ?? 0), (int) ($r['user_id'] ?? 0), (int) ($r['store_id'] ?? 0),
                (string) $r['start_date'], (string) $r['end_date'], (string) ($r['type'] ?? 'vacation'), (string) ($r['status'] ?? 'pending'));
        }
        return $absences;
    }
}
