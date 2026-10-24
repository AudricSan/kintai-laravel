<?php

declare(strict_types=1);

namespace kintai\Core\Services\BundleInstaller;

use kintai\Core\Auth\PermissionCatalog;
use kintai\Core\Extension\ExtensionRegistry;
use kintai\Core\Repositories\RoleRepositoryInterface;

/**
 * À la première installation d'un bundle (assistant de configuration ou catalogue), accorde au rôle Manager les
 * catégories de permissions que le bundle déclare (Bundle::permissions(), contrat 1.2). Le Manager agit sur ses
 * magasins (portée de ses affectations) ; l'Owner peut retirer ces droits dans Rôles. Une mise à jour ne réaccorde
 * rien : un droit retiré par l'Owner le reste.
 */
final class BundlePermissionGranter
{
    public function __construct(private readonly RoleRepositoryInterface $roles) {}

    /**
     * Accorde au rôle Manager les catégories déclarées par la classe d'entrée du bundle.
     *
     * @return list<string> Clés nouvellement accordées
     */
    public function grantDeclaredBy(string $entryClass): array
    {
        return $this->grantToManager(self::declaredPermissions($entryClass, array_keys(PermissionCatalog::forActiveBundles())));
    }

    /**
     * Catégories déclarées par la classe d'entrée d'un bundle (instanciée sans constructeur, comme à la découverte),
     * retenues selon les règles de la page Rôles (ExtensionRegistry::permissions()) : une catégorie mal formée est
     * écartée en entier, et une catégorie déjà prise (Core ou autre bundle actif) n'est jamais accordée par ce bundle.
     *
     * @param list<string> $taken Catégories existantes : Core et bundles actifs
     * @return array<string, list<string>>
     */
    public static function declaredPermissions(string $entryClass, array $taken): array
    {
        $declared = (new \ReflectionClass($entryClass))->newInstanceWithoutConstructor()->permissions();
        $categories = [];
        foreach (is_array($declared) ? $declared : [] as $category => $actions) {
            if (ExtensionRegistry::isValidPermissionCategory($category, $actions) && !in_array($category, $taken, true)) {
                $categories[$category] = array_values(array_unique($actions));
            }
        }
        return $categories;
    }

    /**
     * Ajoute ces permissions au rôle Manager (rôle système « manager »), sans toucher à celles qu'il a déjà ni à leur
     * portée.
     *
     * @param array<string, list<string>> $categories
     * @return list<string> Clés nouvellement accordées
     */
    public function grantToManager(array $categories): array
    {
        $manager = $this->roles->findBySlug('manager');
        if ($manager === null) {
            return [];
        }
        $roleId = (int) $manager['id'];
        $current = $this->roles->getPermissions($roleId);
        $new = [];
        foreach ($categories as $category => $actions) {
            foreach ($actions as $action) {
                $key = $category . '.' . $action;
                if (!in_array($key, $current, true) && !in_array($key, $new, true)) {
                    $new[] = $key;
                }
            }
        }
        if ($new !== []) {
            $this->roles->savePermissions($roleId, array_merge($current, $new), $this->roles->getGlobalPermissionKeys($roleId));
        }
        return $new;
    }
}
