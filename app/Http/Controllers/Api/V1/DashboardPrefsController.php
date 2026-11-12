<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Dashboard\ResetDashboardLayout;
use App\Actions\Dashboard\SaveDashboardLayout;
use App\Authorization\Authorizer;
use App\Dashboard\DashboardLayoutResolver;
use App\Dashboard\DashboardPlacement;
use App\Models\DashboardLayout;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Kintai\Contracts\Dashboard\DashboardWidget;

/**
 * Disposition d'un tableau de bord (`dashboard` : `manager` ou `employee`, par défaut celui de l'utilisateur) : la
 * disposition affichée (personnelle, d'un rôle ou par défaut), et les widgets que l'utilisateur peut y placer.
 */
class DashboardPrefsController extends ApiController
{
    public function show(Request $request, int $user, DashboardLayoutResolver $resolver): JsonResponse
    {
        $target = $this->targetUser($request, $user, 'employees.view');
        $data = $request->validate(['dashboard' => ['nullable', Rule::in([DashboardWidget::MANAGER, DashboardWidget::EMPLOYEE])]]);

        return $this->payload($resolver, $target, $data['dashboard'] ?? $this->defaultAudience($target));
    }

    /**
     * `widgets` : liste de {widget, size?} dans l'ordre d'affichage (crée ou met à jour la disposition personnelle active) ;
     * ou `reset: true` pour revenir à la disposition du rôle ou par défaut.
     */
    public function update(Request $request, int $user, DashboardLayoutResolver $resolver, SaveDashboardLayout $save, ResetDashboardLayout $reset): JsonResponse
    {
        $target = $this->targetUser($request, $user, 'employees.update');

        $data = $request->validate([
            'dashboard' => ['nullable', Rule::in([DashboardWidget::MANAGER, DashboardWidget::EMPLOYEE])],
            'reset' => ['nullable', 'boolean'],
            'name' => ['nullable', 'string', 'max:100'],
            'widgets' => ['required_without:reset', 'array'],
            'widgets.*' => ['array'],
            'widgets.*.widget' => ['required', 'string', 'max:100'],
            'widgets.*.size' => ['nullable', 'string', 'max:10'],
        ]);
        $audience = $data['dashboard'] ?? $this->defaultAudience($target);

        if ($data['reset'] ?? false) {
            $reset->handle($target, $audience);
        } else {
            $existing = DashboardLayout::query()->personal($target, $audience)->where('is_active', true)->orderBy('id')->first();
            $save->handle($target, $audience, $data['name'] ?? $existing->name ?? __('My layout'), $data['widgets'], $existing);
        }

        return $this->payload($resolver, $target, $audience);
    }

    private function defaultAudience(User $user): string
    {
        return app(Authorizer::class)->isManager($user) ? DashboardWidget::MANAGER : DashboardWidget::EMPLOYEE;
    }

    private function payload(DashboardLayoutResolver $resolver, User $user, string $audience): JsonResponse
    {
        $layout = $resolver->layoutFor($user, $audience);

        return response()->json(['data' => [
            'user_id' => $user->id,
            'dashboard' => $audience,
            'source' => $resolver->sourceOf($user, $audience),
            'layout' => $layout === null ? null : ['id' => $layout->id, 'name' => $layout->name],
            'widgets' => array_map(fn (DashboardPlacement $placement): array => [
                'widget' => $placement->widget->key,
                'label' => __($placement->widget->label),
                'size' => $placement->size->value,
            ], $resolver->resolve($user, $audience)),
            'available' => array_map(fn (DashboardWidget $widget): array => [
                'widget' => $widget->key,
                'label' => __($widget->label),
                'size' => $widget->size->value,
                'resizable' => $widget->resizable,
                'default' => $widget->default,
            ], $resolver->available($user, $audience)),
        ]]);
    }
}
