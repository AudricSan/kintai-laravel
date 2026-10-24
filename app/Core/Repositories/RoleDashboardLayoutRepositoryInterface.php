<?php

declare(strict_types=1);

namespace kintai\Core\Repositories;

/** Mise en page par défaut d'un rôle, définie par l'Owner, pour chaque tableau de bord. */
interface RoleDashboardLayoutRepositoryInterface
{
    /** @return array<string, mixed>|null disposition (DashboardLayout::normalize) ou null si le rôle n'en a pas */
    public function get(int $roleId, string $dashboardType): ?array;

    public function save(int $roleId, string $dashboardType, array $layout): void;

    public function delete(int $roleId, string $dashboardType): void;

    /** Supprime les mises en page par défaut d'un rôle supprimé. */
    public function deleteForRole(int $roleId): void;
}
