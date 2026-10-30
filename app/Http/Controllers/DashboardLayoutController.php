<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use kintai\Contract\Extension\DashboardWidget;
use App\Exceptions\ValidationException;
use App\Extension\DashboardLayout;
use App\Extension\DashboardWidgets;
use App\Models\UserDashboardLayout;
use App\Models\UserDashboardPref;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Services\AuditLogger;

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
        private readonly AuditLogger $auditLogger,
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
        $userId = (int) ($request->attributes->get('auth_user')['id'] ?? 0);
        $body   = $request->json()->all() ?? [];

        if (($body['reset'] ?? false) === true) {
            UserDashboardPref::saveLayout($userId, $dashboardType, null);
            // Revenir au défaut : la disposition en cours n'est plus celle d'une mise en page nommée.
            // Mises en page nommées : plus aucune n'est active.
            UserDashboardLayout::deactivateAll($userId, $dashboardType);
            return response()->json(['layout' => null]);
        }

        $layout = DashboardLayout::update(
            $offered,
            UserDashboardPref::getLayout($userId, $dashboardType),
            is_array($body['order'] ?? null) ? array_values($body['order']) : [],
            is_array($body['widths'] ?? null) ? $body['widths'] : [],
            // Hauteurs, widgets repliés et densité : seulement ce que la page envoie (le reste est conservé).
            array_intersect_key($body, array_flip(['heights', 'collapsed', 'density'])),
        );
        UserDashboardPref::saveLayout($userId, $dashboardType, $layout);
        // Mises en page nommées : la modification est reportée sur celle qui est active.
        UserDashboardLayout::updateActive($userId, $dashboardType, $layout);

        return response()->json(['layout' => $layout]);
    }

    /**
     * @param list<string> $offered  Widgets proposés sur ce tableau de bord
     * @param list<string> $defaults Widgets affichés tant que l'utilisateur n'a rien choisi
     */
    private function toggle(Request $request, string $dashboardType, array $offered, array $defaults): Response
    {
        $userId = (int) ($request->attributes->get('auth_user')['id'] ?? 0);
        $body   = $request->json()->all() ?? [];
        $add    = is_string($body['add'] ?? null);
        $key    = $add ? $body['add'] : ($body['remove'] ?? null);

        if (!is_string($key) || !in_array($key, $offered, true)) {
            throw new ValidationException(['widget' => __('dashboard_widget_unknown')]);
        }

        $previous = UserDashboardPref::getEnabledWidgets($userId, $dashboardType);
        $widgets  = DashboardWidgets::toggle($defaults, $previous, $key, $add);
        UserDashboardPref::saveWidgets($userId, $widgets, $dashboardType);

        // Un widget ajouté arrive en fin de tableau de bord, sans toucher à la place des autres.
        if ($add) {
            UserDashboardPref::saveLayout(
                $userId,
                $dashboardType,
                DashboardLayout::moveToEnd($offered, UserDashboardPref::getLayout($userId, $dashboardType), $key),
            );
        }

        $this->auditLogger->logUpdate($request, 'dashboard_prefs.updated', 'user_dashboard_prefs', $userId, $previous, $widgets, ['dashboard' => $dashboardType]);

        return response()->json(['widgets' => $widgets]);
    }
}
