<?php

declare(strict_types=1);

namespace kintai\UI\Controller\Web;

use kintai\Contract\Extension\DashboardWidget;
use kintai\Core\Exceptions\ValidationException;
use kintai\Core\Extension\DashboardLayout;
use kintai\Core\Extension\DashboardWidgets;
use kintai\Core\Repositories\UserDashboardLayoutRepositoryInterface;
use kintai\Core\Repositories\UserDashboardPrefsRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Response;
use kintai\Core\Services\AuditLogger;

/**
 * Personnalisation des tableaux de bord, appelée en arrière-plan par dashboard-layout.js en mode édition :
 * - disposition (ordre, largeur, hauteur, repli des widgets et densité), à chaque modification. Corps JSON : {order: [...], widths: {clé:
 *   half|full}} pour les widgets affichés, ou {reset: true} pour revenir à la disposition par défaut ;
 * - widgets affichés : {add: clé} ajoute un widget en fin de tableau de bord, {remove: clé} le retire. Les autres
 *   widgets gardent leur place et leur largeur.
 */
final class DashboardLayoutController
{
    public function __construct(
        private readonly UserDashboardPrefsRepositoryInterface $dashboardPrefs,
        private readonly AuditLogger $auditLogger,
        // Mises en page nommées : la modification de la disposition en cours est reportée sur celle qui est active.
        private readonly ?UserDashboardLayoutRepositoryInterface $layouts = null,
    ) {}

    /** POST /admin/dashboard/layout */
    public function saveManager(Request $request): Response
    {
        return $this->save($request, 'admin', HomeController::offeredWidgetKeys(DashboardWidgets::current()));
    }

    /** POST /employee/dashboard/layout */
    public function saveEmployee(Request $request): Response
    {
        return $this->save($request, 'employee', DashboardWidgets::current()->keys(DashboardWidget::EMPLOYEE, EmployeeController::EMPLOYEE_WIDGETS));
    }

    /** POST /admin/dashboard/widgets */
    public function widgetsManager(Request $request): Response
    {
        $widgets = DashboardWidgets::current();
        return $this->toggle($request, 'admin', HomeController::offeredWidgetKeys($widgets), HomeController::defaultWidgetKeys($widgets));
    }

    /** POST /employee/dashboard/widgets */
    public function widgetsEmployee(Request $request): Response
    {
        $keys = DashboardWidgets::current()->keys(DashboardWidget::EMPLOYEE, EmployeeController::EMPLOYEE_WIDGETS);
        return $this->toggle($request, 'employee', $keys, $keys);
    }

    /** @param list<string> $offered Widgets proposés sur ce tableau de bord */
    private function save(Request $request, string $dashboardType, array $offered): Response
    {
        $userId = (int) ($request->getAttribute('auth_user')['id'] ?? 0);
        $body   = $request->json() ?? [];

        if (($body['reset'] ?? false) === true) {
            $this->dashboardPrefs->saveLayout($userId, $dashboardType, null);
            // Revenir au défaut : la disposition en cours n'est plus celle d'une mise en page nommée.
            $this->layouts?->deactivateAll($userId, $dashboardType);
            return Response::json(['layout' => null]);
        }

        $layout = DashboardLayout::update(
            $offered,
            $this->dashboardPrefs->getLayout($userId, $dashboardType),
            is_array($body['order'] ?? null) ? array_values($body['order']) : [],
            is_array($body['widths'] ?? null) ? $body['widths'] : [],
            // Hauteurs, widgets repliés et densité : seulement ce que la page envoie (le reste est conservé).
            array_intersect_key($body, array_flip(['heights', 'collapsed', 'density'])),
        );
        $this->dashboardPrefs->saveLayout($userId, $dashboardType, $layout);
        $this->layouts?->updateActive($userId, $dashboardType, $layout);

        return Response::json(['layout' => $layout]);
    }

    /**
     * @param list<string> $offered  Widgets proposés sur ce tableau de bord
     * @param list<string> $defaults Widgets affichés tant que l'utilisateur n'a rien choisi
     */
    private function toggle(Request $request, string $dashboardType, array $offered, array $defaults): Response
    {
        $userId = (int) ($request->getAttribute('auth_user')['id'] ?? 0);
        $body   = $request->json() ?? [];
        $add    = is_string($body['add'] ?? null);
        $key    = $add ? $body['add'] : ($body['remove'] ?? null);

        if (!is_string($key) || !in_array($key, $offered, true)) {
            throw new ValidationException(['widget' => __('dashboard_widget_unknown')]);
        }

        $previous = $this->dashboardPrefs->getEnabledWidgets($userId, $dashboardType);
        $widgets  = DashboardWidgets::toggle($defaults, $previous, $key, $add);
        $this->dashboardPrefs->saveWidgets($userId, $widgets, $dashboardType);

        // Un widget ajouté arrive en fin de tableau de bord, sans toucher à la place des autres.
        if ($add) {
            $this->dashboardPrefs->saveLayout(
                $userId,
                $dashboardType,
                DashboardLayout::moveToEnd($offered, $this->dashboardPrefs->getLayout($userId, $dashboardType), $key),
            );
        }

        $this->auditLogger->logUpdate($request, 'dashboard_prefs.updated', 'user_dashboard_prefs', $userId, $previous, $widgets, ['dashboard' => $dashboardType]);

        return Response::json(['widgets' => $widgets]);
    }
}
