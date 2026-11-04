<?php

namespace App\Actions\Dashboard;

use App\Dashboard\DashboardLayoutResolver;
use App\Models\DashboardLayout;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveDashboardLayout
{
    public function __construct(private readonly DashboardLayoutResolver $resolver) {}

    /**
     * Enregistre une disposition personnelle nommée (nouvelle, ou `$existing` mise à jour) et l'active.
     * Les widgets inconnus ou d'une autre audience sont écartés ; une disposition vide est refusée.
     *
     * @param  array<int, mixed>  $layout  Liste de {widget, size?}
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $user, string $audience, string $name, array $layout, ?DashboardLayout $existing = null): DashboardLayout
    {
        if ($existing !== null && ($existing->user_id !== $user->id || $existing->dashboard !== $audience)) {
            throw new AuthorizationException;
        }

        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 100) {
            throw ValidationException::withMessages(['name' => __('Give the layout a name of up to 100 characters.')]);
        }

        $normalized = $this->resolver->normalize($audience, $layout);
        if ($normalized === []) {
            throw ValidationException::withMessages(['layout' => __('Add at least one widget.')]);
        }

        return DB::transaction(function () use ($user, $audience, $name, $normalized, $existing): DashboardLayout {
            DashboardLayout::query()->personal($user, $audience)->update(['is_active' => false]);

            $layout = $existing ?? new DashboardLayout(['user_id' => $user->id, 'dashboard' => $audience]);
            $layout->fill(['name' => $name, 'layout' => $normalized, 'is_active' => true])->save();

            return $layout;
        });
    }
}
