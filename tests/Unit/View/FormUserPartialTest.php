<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\View;

use PHPUnit\Framework\TestCase;

final class FormUserPartialTest extends TestCase
{
    private function render(string $mode, array $user, array $all_stores, array $all_roles = [], int $default_store_role_id = 0): string
    {
        $BASE_URL = '';
        ob_start();
        include __DIR__ . '/../../../app/UI/View/_partials/_form-user.php';
        return ob_get_clean();
    }

    private const ROLES = [
        ['id' => 2, 'name' => 'Manager', 'is_managing' => true],
        ['id' => 3, 'name' => 'Employé', 'is_managing' => false],
    ];

    private const ROLES_WITH_OWNER = [
        ['id' => 1, 'name' => 'Owner', 'is_system' => 1, 'is_managing' => true],
        ['id' => 2, 'name' => 'Manager', 'is_managing' => true],
        ['id' => 3, 'name' => 'Employé', 'is_managing' => false],
    ];

    // Contexte de la modale de création rapide (import de shifts) : le store est déjà
    // fixé par un champ <input type="hidden" name="store_id"> du formulaire parent.
    // Si ce partial rendait aussi un <select name="store_id">, la valeur du champ
    // caché serait écrasée côté serveur (PHP ne retient que la dernière occurrence
    // d'un nom de champ POST dupliqué), empêchant tout rattachement au store.
    public function testCreateModeWithoutStoresOmitsStoreIdSelect(): void
    {
        $html = $this->render('create', [], [], self::ROLES, 3);

        $this->assertStringNotContainsString('name="store_id"', $html);
        $this->assertStringContainsString('name="role_id"', $html);
    }

    public function testCreateModeWithStoresRendersStoreIdSelect(): void
    {
        $html = $this->render('create', [], [
            ['id' => 3, 'name' => 'Store A', 'code' => 'A'],
        ], self::ROLES, 3);

        $this->assertStringContainsString('name="store_id"', $html);
        $this->assertStringContainsString('name="role_id"', $html);
    }

    public function testRoleSelectListsDynamicRolesAndSelectsDefault(): void
    {
        $html = $this->render('create', [], [], self::ROLES, 3);

        $this->assertStringContainsString('Manager', $html);
        $this->assertStringContainsString('Employé', $html);
        $this->assertMatchesRegularExpression('/<option value="3"\s+data-system="0"\s+selected>/', $html);
    }

    /**
     * Le sélecteur "Rôle" unifié remplace l'ancienne case "Rôle global"
     * (Personnel/Administration) : Owner doit y apparaître comme n'importe
     * quel autre rôle, marqué data-system="1" (utilisé par user-role-select.js
     * pour masquer le sélecteur de store, non nécessaire pour ce rôle).
     */
    public function testRoleSelectIncludesOwnerWhenPresent(): void
    {
        $html = $this->render('create', [], [], self::ROLES_WITH_OWNER, 3);

        $this->assertStringContainsString('Owner', $html);
        $this->assertMatchesRegularExpression('/<option value="1"\s+data-system="1"/', $html);
        // Rôle global (Personnel/Administration) fusionné dans ce sélecteur : ne doit plus apparaître.
        $this->assertStringNotContainsString('name="is_admin"', $html);
    }

    public function testRoleSelectOmittedWithoutRoles(): void
    {
        $html = $this->render('create', [], []);

        $this->assertStringNotContainsString('name="role_id"', $html);
        // Sans all_roles (ex. modale de création rapide), l'ancienne case Owner reste disponible.
        $this->assertStringContainsString('name="is_admin"', $html);
    }

    public function testEditModeNeverRendersStoreAssignmentBlock(): void
    {
        $html = $this->render('edit', ['id' => 1, 'display_name' => 'Jane'], [
            ['id' => 3, 'name' => 'Store A', 'code' => 'A'],
        ], self::ROLES, 3);

        $this->assertStringNotContainsString('name="store_id"', $html);
        $this->assertStringNotContainsString('name="role_id"', $html);
    }

    /** L'édition garde la case "compte Owner" (pas de contexte de store unique sur cette page). */
    public function testEditModeRendersOwnerCheckbox(): void
    {
        $html = $this->render('edit', ['id' => 1, 'display_name' => 'Jane', 'is_admin' => 1], []);

        $this->assertStringContainsString('name="is_admin"', $html);
        $this->assertStringContainsString('checked', $html);
    }
}
