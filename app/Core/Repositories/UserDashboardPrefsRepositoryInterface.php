<?php

declare(strict_types=1);


namespace kintai\Core\Repositories;

interface UserDashboardPrefsRepositoryInterface
{
    /**
     * Returns saved widget keys for the given user+dashboard, or null if no
     * preferences have been saved yet (caller should fall back to its own defaults).
     *
     * @return string[]|null
     */
    public function getEnabledWidgets(int $userId, string $dashboardType): ?array;

    /** @param string[] $widgets */
    public function saveWidgets(int $userId, array $widgets, string $dashboardType): void;

    /**
     * Disposition enregistrée (ordre et largeur des widgets, voir DashboardLayout), ou null : disposition par défaut.
     *
     * @return array{order: list<string>, widths: array<string, string>}|null
     */
    public function getLayout(int $userId, string $dashboardType): ?array;

    /**
     * Enregistre la disposition, sans toucher aux widgets activés ; null revient à la disposition par défaut.
     *
     * @param array{order: list<string>, widths: array<string, string>}|null $layout
     */
    public function saveLayout(int $userId, string $dashboardType, ?array $layout): void;
}
