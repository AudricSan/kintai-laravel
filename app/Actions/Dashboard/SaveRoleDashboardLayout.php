<?php

namespace App\Actions\Dashboard;

use App\Dashboard\DashboardLayoutResolver;
use App\Models\DashboardLayout;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class SaveRoleDashboardLayout
{
    public function __construct(private readonly DashboardLayoutResolver $resolver) {}

    /**
     * Définit la disposition imposée à un rôle pour un tableau de bord (une par rôle et par tableau).
     * Réservé à l'Owner.
     *
     * @param  array<int, mixed>  $layout  Liste de {widget, size?}
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, Role $role, string $audience, array $layout, int $position = 0): DashboardLayout
    {
        if (! Gate::forUser($actor)->allows('manage-roles')) {
            throw new AuthorizationException;
        }

        $normalized = $this->resolver->normalize($audience, $layout);
        if ($normalized === []) {
            throw ValidationException::withMessages(['layout' => __('Add at least one widget.')]);
        }

        return DashboardLayout::query()->updateOrCreate(
            ['user_id' => null, 'role_id' => $role->id, 'dashboard' => $audience],
            ['name' => $role->name, 'layout' => $normalized, 'is_active' => true, 'position' => $position],
        );
    }
}
