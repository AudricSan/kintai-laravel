<?php

declare(strict_types=1);

namespace kintai\UI\Controller\Api\V1;

use kintai\Core\Auth\UserTargetGuard;
use kintai\Core\Exceptions\ValidationException;
use kintai\Core\Extension\DashboardLayout;
use kintai\Core\Repositories\UserDashboardPrefsRepositoryInterface;
use kintai\Core\Repositories\UserNavPrefsRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Response;
use kintai\Core\Validation\ApiPayloadRules;
use kintai\Core\Validation\PayloadValidator;

final class UserPrefsController
{
    public function __construct(
        private readonly UserDashboardPrefsRepositoryInterface $dashboardPrefs,
        private readonly UserNavPrefsRepositoryInterface $navPrefs,
        private readonly UserTargetGuard $guard,
    ) {}

    /** GET /api/v1/users/{user_id}/dashboard-prefs?type=admin */
    public function getDashboardPrefs(Request $request): Response
    {
        $userId        = (int) $request->param('user_id');
        $dashboardType = $request->query('type', 'admin') ?? 'admin';
        $this->guard->require($request, $userId, 'employees.view');

        return Response::json([
            'user_id'        => $userId,
            'dashboard_type' => $dashboardType,
            'widgets'        => $this->dashboardPrefs->getEnabledWidgets($userId, $dashboardType),
            'layout'         => $this->dashboardPrefs->getLayout($userId, $dashboardType),
        ]);
    }

    /**
     * PUT /api/v1/users/{user_id}/dashboard-prefs
     *
     * Champs facultatifs, chacun enregistré seulement s'il est envoyé : `widgets` (widgets activés) et `layout`
     * ({order: [...], widths: {clé: half|full}} remplace la disposition ; null revient à la disposition par défaut).
     */
    public function saveDashboardPrefs(Request $request): Response
    {
        $userId        = (int) $request->param('user_id');
        $this->guard->require($request, $userId, 'employees.update');

        $payload       = $request->json() ?? [];
        $data          = PayloadValidator::validate($payload, ApiPayloadRules::dashboardPrefs());
        $dashboardType = (string) ($data['dashboard_type'] ?? 'admin');

        if (array_key_exists('widgets', $payload)) {
            $this->dashboardPrefs->saveWidgets($userId, $data['widgets'] ?? [], $dashboardType);
        }
        if (array_key_exists('layout', $payload)) {
            $layout = $payload['layout'];
            if ($layout !== null && (!is_array($layout) || array_is_list($layout) && $layout !== [])) {
                throw new ValidationException(['layout' => [__('val_field_invalid', ['field' => 'layout'])]]);
            }
            $this->dashboardPrefs->saveLayout($userId, $dashboardType, $layout === null ? null : DashboardLayout::normalize($layout));
        }

        return Response::json([
            'user_id'        => $userId,
            'dashboard_type' => $dashboardType,
            'widgets'        => $this->dashboardPrefs->getEnabledWidgets($userId, $dashboardType),
            'layout'         => $this->dashboardPrefs->getLayout($userId, $dashboardType),
        ]);
    }

    /** GET /api/v1/users/{user_id}/nav-prefs */
    public function getNavPrefs(Request $request): Response
    {
        $userId = (int) $request->param('user_id');
        $this->guard->require($request, $userId, 'employees.view');

        return Response::json([
            'user_id' => $userId,
            'hidden'  => $this->navPrefs->getHidden($userId),
        ]);
    }

    /** PUT /api/v1/users/{user_id}/nav-prefs */
    public function saveNavPrefs(Request $request): Response
    {
        $userId = (int) $request->param('user_id');
        $this->guard->require($request, $userId, 'employees.update');

        $data   = PayloadValidator::validate($request->json() ?? [], ApiPayloadRules::navPrefs());
        $hidden = $data['hidden'] ?? [];

        $this->navPrefs->saveHidden($userId, $hidden);

        return Response::json(['user_id' => $userId, 'hidden' => $hidden]);
    }
}
