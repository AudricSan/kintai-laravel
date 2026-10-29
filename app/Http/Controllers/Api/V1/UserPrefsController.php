<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Auth\UserTargetGuard;
use App\Exceptions\ValidationException;
use App\Extension\DashboardLayout;
use App\Repositories\UserDashboardPrefsRepositoryInterface;
use App\Repositories\UserNavPrefsRepositoryInterface;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Validation\ApiPayloadRules;
use App\Validation\PayloadValidator;

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
        $userId        = (int) $request->route('user_id');
        $dashboardType = $request->query('type', 'admin') ?? 'admin';
        $this->guard->require($request, $userId, 'employees.view');

        return response()->json([
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
        $userId        = (int) $request->route('user_id');
        $this->guard->require($request, $userId, 'employees.update');

        $payload       = $request->json()->all() ?? [];
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

        return response()->json([
            'user_id'        => $userId,
            'dashboard_type' => $dashboardType,
            'widgets'        => $this->dashboardPrefs->getEnabledWidgets($userId, $dashboardType),
            'layout'         => $this->dashboardPrefs->getLayout($userId, $dashboardType),
        ]);
    }

    /** GET /api/v1/users/{user_id}/nav-prefs */
    public function getNavPrefs(Request $request): Response
    {
        $userId = (int) $request->route('user_id');
        $this->guard->require($request, $userId, 'employees.view');

        return response()->json([
            'user_id' => $userId,
            'hidden'  => $this->navPrefs->getHidden($userId),
        ]);
    }

    /** PUT /api/v1/users/{user_id}/nav-prefs */
    public function saveNavPrefs(Request $request): Response
    {
        $userId = (int) $request->route('user_id');
        $this->guard->require($request, $userId, 'employees.update');

        $data   = PayloadValidator::validate($request->json()->all() ?? [], ApiPayloadRules::navPrefs());
        $hidden = $data['hidden'] ?? [];

        $this->navPrefs->saveHidden($userId, $hidden);

        return response()->json(['user_id' => $userId, 'hidden' => $hidden]);
    }
}
