<?php

declare(strict_types=1);

namespace kintai\Core\Services;

use kintai\Core\Repositories\RoleAssignmentRepositoryInterface;
use kintai\Core\Repositories\RoleDashboardLayoutRepositoryInterface;
use kintai\Core\Repositories\RoleRepositoryInterface;
use kintai\Core\Repositories\UserDashboardLayoutRepositoryInterface;
use kintai\Core\Repositories\UserDashboardPrefsRepositoryInterface;

/**
 * Disposition d'un tableau de bord à afficher pour un utilisateur : celle qu'il a enregistrée, sinon la mise en page par
 * défaut de son rôle (définie par l'Owner), sinon null (disposition par défaut du Core).
 *
 * Le défaut d'un rôle ne s'applique donc qu'à qui n'a rien enregistré : un utilisateur qui a déjà personnalisé son
 * tableau de bord le garde. S'il a plusieurs rôles, le premier qui a un défaut l'emporte : rôles système (Owner) d'abord,
 * puis par identifiant croissant.
 */
final class DashboardLayoutResolver
{
    public function __construct(
        private readonly UserDashboardPrefsRepositoryInterface $prefs,
        private readonly RoleDashboardLayoutRepositoryInterface $roleLayouts,
        private readonly RoleAssignmentRepositoryInterface $assignments,
        private readonly RoleRepositoryInterface $roles,
        private readonly UserDashboardLayoutRepositoryInterface $named,
    ) {}

    /** Mises en page nommées de l'utilisateur pour ce tableau de bord (panneau « Personnaliser »). */
    public function namedLayouts(int $userId, string $dashboardType): array
    {
        return $this->named->forUser($userId, $dashboardType);
    }

    /**
     * Rôles proposés à l'Owner pour y définir le défaut du tableau de bord (id => nom), avec ceux qui en ont déjà un.
     *
     * @return array<int, array{name: string, has_default: bool}>
     */
    public function roleOptions(string $dashboardType): array
    {
        $options = [];
        foreach ($this->roles->findAll() as $role) {
            $options[(int) $role['id']] = [
                'name'        => (string) ($role['name'] ?? $role['slug'] ?? ('#' . $role['id'])),
                'has_default' => $this->roleLayouts->get((int) $role['id'], $dashboardType) !== null,
            ];
        }
        return $options;
    }

    /** @return array<string, mixed>|null */
    public function layoutFor(int $userId, string $dashboardType): ?array
    {
        return $this->prefs->getLayout($userId, $dashboardType) ?? $this->roleDefault($userId, $dashboardType);
    }

    /** Défaut du rôle de l'utilisateur, ou null : « Revenir au défaut de mon rôle ». @return array<string, mixed>|null */
    public function roleDefault(int $userId, string $dashboardType): ?array
    {
        foreach ($this->roleIds($userId) as $roleId) {
            $layout = $this->roleLayouts->get($roleId, $dashboardType);
            if ($layout !== null) {
                return $layout;
            }
        }
        return null;
    }

    /** @return list<int> */
    private function roleIds(int $userId): array
    {
        $ids = [];
        foreach ($this->assignments->findByUser($userId) as $assignment) {
            $ids[(int) $assignment['role_id']] = true;
        }
        $ids = array_keys($ids);
        usort($ids, function (int $a, int $b): int {
            $systemA = !empty($this->roles->findById($a)['is_system']) ? 0 : 1;
            $systemB = !empty($this->roles->findById($b)['is_system']) ? 0 : 1;
            return [$systemA, $a] <=> [$systemB, $b];
        });
        return $ids;
    }
}
