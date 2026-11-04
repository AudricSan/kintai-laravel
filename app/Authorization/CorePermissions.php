<?php

namespace App\Authorization;

/**
 * Permissions déclarées par le Core de l'application.
 */
final class CorePermissions
{
    /** @var array<string, list<string>> */
    public const CATEGORIES = [
        'employees' => ['view', 'create', 'update', 'delete'],
        'shifts' => ['view', 'create', 'update', 'delete', 'import'],
        'stores' => ['view', 'create', 'update', 'delete'],
        'payroll' => ['view', 'generate', 'export'],
    ];

    /** @var list<string> */
    public const MANAGER_DEFAULTS = [
        'employees.view',
        'employees.create',
        'employees.update',
        'employees.delete',
        'shifts.view',
        'shifts.create',
        'shifts.update',
        'shifts.delete',
        'shifts.import',
        'stores.view',
        'stores.update',
        'payroll.view',
        'payroll.generate',
        'payroll.export',
    ];

    /**
     * Toutes les clés `catégorie.action` du Core.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        $keys = [];
        foreach (self::CATEGORIES as $category => $actions) {
            foreach ($actions as $action) {
                $keys[] = $category.'.'.$action;
            }
        }

        return $keys;
    }

    /**
     * Enregistre les catégories du Core dans le registre.
     */
    public static function registerIn(PermissionRegistry $registry): void
    {
        foreach (self::CATEGORIES as $category => $actions) {
            $registry->register($category, $actions);
        }
    }
}
