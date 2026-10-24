<?php

declare(strict_types=1);

namespace kintai\Core\Auth;

/**
 * Catalogue des permissions disponibles, groupées par catégorie.
 *
 * Les permissions sont un vocabulaire de développeur (comme les clés de
 * features de store) : un rôle est associé dynamiquement à un sous-ensemble
 * de ces clés en base (table role_permissions), mais les clés elles-mêmes
 * sont codées ici et s'enrichissent par une modification de ce fichier.
 */
final class PermissionCatalog
{
    public const CATEGORIES = [
        'employees'            => ['view', 'create', 'update', 'delete'],
        'shifts'               => ['view', 'create', 'update', 'delete', 'import'],
        'stores'               => ['view', 'create', 'update', 'delete'],
        'payroll'              => ['view', 'generate', 'export'],
    ];

    /**
     * Ensemble de permissions par défaut du rôle "Manager" seedé à l'installation.
     * Reflète les capacités de gestion de store actuelles (hors réglages
     * d'instance, réservés à Owner).
     */
    public const MANAGER_DEFAULTS = [
        'employees.view', 'employees.create', 'employees.update', 'employees.delete',
        'shifts.view', 'shifts.create', 'shifts.update', 'shifts.delete', 'shifts.import',
        'stores.view', 'stores.update',
        'payroll.view', 'payroll.generate', 'payroll.export',
    ];

    /** Catégories du Core : toujours présentes, jamais redéfinissables par un bundle. */
    public const CORE_CATEGORIES = ['employees', 'shifts', 'stores', 'payroll'];

    /**
     * Catégories proposées dans la page des rôles : celles du Core, puis celles déclarées par les bundles actifs
     * (Bundle::permissions(), contrat 1.2) dans l'ordre de leur déclaration. Une catégorie de bundle inactif n'est pas
     * proposée ; ses clés restent en base et réapparaissent si le bundle est réactivé.
     *
     * @return array<string, list<string>>
     */
    public static function forActiveBundles(): array
    {
        $registry = null;
        try {
            $container = \kintai\Core\Container::getInstance();
            if ($container->has(\kintai\Core\Extension\ExtensionRegistry::class)) {
                $registry = $container->make(\kintai\Core\Extension\ExtensionRegistry::class);
            }
        } catch (\Throwable) {
        }

        return self::CATEGORIES + ($registry?->permissions(self::CORE_CATEGORIES) ?? []);
    }

    /** @return string[] Toutes les clés de permission ("categorie.action"), aplaties. */
    public static function all(): array
    {
        $keys = [];
        foreach (self::CATEGORIES as $category => $actions) {
            foreach ($actions as $action) {
                $keys[] = $category . '.' . $action;
            }
        }
        return $keys;
    }

    public static function exists(string $key): bool
    {
        return in_array($key, self::all(), true);
    }
}
