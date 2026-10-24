<?php

declare(strict_types=1);

namespace kintai\Core\Repositories;

/** Mises en page nommées d'un utilisateur pour un tableau de bord (instantanés d'une DashboardLayout). */
interface UserDashboardLayoutRepositoryInterface
{
    /** Nombre maximal de mises en page nommées par utilisateur et par tableau de bord. */
    public const MAX_PER_DASHBOARD = 5;

    /** @return list<array{id: int, name: string, layout: array<string, mixed>, is_active: bool, position: int}> */
    public function forUser(int $userId, string $dashboardType): array;

    /** @return array{id: int, name: string, layout: array<string, mixed>, is_active: bool, position: int, user_id: int, dashboard_type: string}|null */
    public function find(int $id): ?array;

    /** Crée la mise en page et la rend active (les autres du même tableau de bord ne le sont plus). Renvoie son id. */
    public function createActive(int $userId, string $dashboardType, string $name, array $layout): int;

    public function rename(int $id, string $name): void;

    public function delete(int $id): void;

    /** Active la mise en page $id (les autres du même tableau de bord ne le sont plus). */
    public function activate(int $id): void;

    /** Met à jour la mise en page active avec la disposition en cours ; sans effet s'il n'y en a pas. */
    public function updateActive(int $userId, string $dashboardType, array $layout): void;

    /** La disposition en cours ne correspond plus à une mise en page nommée (réinitialisation) : aucune n'est active. */
    public function deactivateAll(int $userId, string $dashboardType): void;
}
