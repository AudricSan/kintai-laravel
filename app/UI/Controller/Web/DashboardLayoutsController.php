<?php

declare(strict_types=1);

namespace kintai\UI\Controller\Web;

use kintai\Core\Extension\DashboardLayout;
use kintai\Core\Repositories\RoleDashboardLayoutRepositoryInterface;
use kintai\Core\Repositories\RoleRepositoryInterface;
use kintai\Core\Repositories\UserDashboardLayoutRepositoryInterface;
use kintai\Core\Repositories\UserDashboardPrefsRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Response;
use kintai\Core\Services\AuditLogger;
use kintai\Core\Services\DashboardLayoutResolver;
use kintai\Core\SessionFlash;

/**
 * Mises en page nommées d'un tableau de bord et défaut par rôle, depuis le panneau « Personnaliser » (formulaires POST,
 * sans JavaScript). Une mise en page nommée est un instantané de la disposition en cours : l'activer la recopie dans
 * user_dashboard_prefs.layout, et les modifications faites ensuite sont reportées sur la mise en page active
 * (voir DashboardLayoutController).
 *
 * Champ `action` :
 * - create        : enregistre la disposition en cours sous `name` et l'active (5 au plus par tableau de bord) ;
 * - activate      : adopte la mise en page `id` ;
 * - rename        : renomme la mise en page `id` ;
 * - delete        : supprime la mise en page `id` (la disposition en cours ne change pas) ;
 * - role_default  : (Owner) fait de la disposition en cours le défaut du rôle `role_id` ;
 * - role_clear    : (Owner) retire le défaut du rôle `role_id`.
 *
 * Un utilisateur ne touche qu'à ses propres mises en page ; le défaut par rôle est réservé aux Owners.
 */
final class DashboardLayoutsController
{
    public const NAME_MAX = 60;

    public function __construct(
        private readonly UserDashboardLayoutRepositoryInterface $layouts,
        private readonly UserDashboardPrefsRepositoryInterface $prefs,
        private readonly RoleDashboardLayoutRepositoryInterface $roleLayouts,
        private readonly RoleRepositoryInterface $roles,
        private readonly DashboardLayoutResolver $resolver,
        private readonly AuditLogger $auditLogger,
    ) {}

    /** POST /admin/dashboard/layouts */
    public function manageManager(Request $request): Response
    {
        return $this->handle($request, 'admin', '/');
    }

    /** POST /employee/dashboard/layouts */
    public function manageEmployee(Request $request): Response
    {
        return $this->handle($request, 'employee', '/employee');
    }

    private function handle(Request $request, string $dashboardType, string $fallback): Response
    {
        $user   = $request->getAttribute('auth_user') ?? [];
        $userId = (int) ($user['id'] ?? 0);
        $action = (string) $request->post('action', '');
        $back   = back_url(base_url() . $fallback);

        $error = match ($action) {
            'create'   => $this->create($userId, $dashboardType, trim((string) $request->post('name', ''))),
            'activate' => $this->owned($userId, $dashboardType, (int) $request->post('id', 0), function (array $layout) use ($userId, $dashboardType): void {
                $this->prefs->saveLayout($userId, $dashboardType, $layout['layout']);
                $this->layouts->activate($layout['id']);
            }),
            'rename'   => $this->rename($userId, $dashboardType, (int) $request->post('id', 0), trim((string) $request->post('name', ''))),
            'delete'   => $this->owned($userId, $dashboardType, (int) $request->post('id', 0), fn(array $layout) => $this->layouts->delete($layout['id'])),
            'role_default', 'role_clear' => $this->roleDefault($request, $user, $dashboardType, $action === 'role_clear'),
            default    => 'dashboard_layouts_invalid',
        };

        if ($error !== null) {
            SessionFlash::put('error', __($error));
        } else {
            $this->auditLogger->log($request, 'dashboard_layouts.' . $action, 'user_dashboard_layouts', $userId, ['dashboard' => $dashboardType], null, $userId);
            SessionFlash::put('success', __('dashboard_layouts_saved'));
        }
        return Response::redirect($back);
    }

    /** @return string|null clé de traduction de l'erreur, ou null si réussi */
    private function create(int $userId, string $dashboardType, string $name): ?string
    {
        if ($name === '' || mb_strlen($name) > self::NAME_MAX) {
            return 'dashboard_layouts_name_invalid';
        }
        $existing = $this->layouts->forUser($userId, $dashboardType);
        if (count($existing) >= UserDashboardLayoutRepositoryInterface::MAX_PER_DASHBOARD) {
            return 'dashboard_layouts_limit';
        }
        foreach ($existing as $layout) {
            if (mb_strtolower($layout['name']) === mb_strtolower($name)) {
                return 'dashboard_layouts_name_taken';
            }
        }
        $current = $this->prefs->getLayout($userId, $dashboardType) ?? $this->resolver->roleDefault($userId, $dashboardType) ?? DashboardLayout::normalize([]);
        $this->layouts->createActive($userId, $dashboardType, $name, $current);
        // La disposition en cours devient celle de la mise en page créée (elle en est l'instantané).
        $this->prefs->saveLayout($userId, $dashboardType, $current);
        return null;
    }

    private function rename(int $userId, string $dashboardType, int $id, string $name): ?string
    {
        if ($name === '' || mb_strlen($name) > self::NAME_MAX) {
            return 'dashboard_layouts_name_invalid';
        }
        foreach ($this->layouts->forUser($userId, $dashboardType) as $layout) {
            if ($layout['id'] !== $id && mb_strtolower($layout['name']) === mb_strtolower($name)) {
                return 'dashboard_layouts_name_taken';
            }
        }
        return $this->owned($userId, $dashboardType, $id, fn(array $layout) => $this->layouts->rename($layout['id'], $name));
    }

    /**
     * Exécute $do sur la mise en page $id si elle appartient à l'utilisateur pour ce tableau de bord.
     *
     * @param callable(array): void $do
     */
    private function owned(int $userId, string $dashboardType, int $id, callable $do): ?string
    {
        $layout = $id > 0 ? $this->layouts->find($id) : null;
        if ($layout === null || $layout['user_id'] !== $userId || $layout['dashboard_type'] !== $dashboardType) {
            return 'dashboard_layouts_not_found';
        }
        $do($layout);
        return null;
    }

    /** @param array<string, mixed> $user */
    private function roleDefault(Request $request, array $user, string $dashboardType, bool $clear): ?string
    {
        if (empty($user['is_admin'])) {
            return 'dashboard_layouts_owner_only';
        }
        $roleId = (int) $request->post('role_id', 0);
        if ($roleId <= 0 || $this->roles->findById($roleId) === null) {
            return 'dashboard_layouts_role_unknown';
        }
        if ($clear) {
            $this->roleLayouts->delete($roleId, $dashboardType);
            return null;
        }
        $current = $this->prefs->getLayout((int) $user['id'], $dashboardType);
        if ($current === null) {
            return 'dashboard_layouts_customize_first';
        }
        $this->roleLayouts->save($roleId, $dashboardType, $current);
        return null;
    }
}
