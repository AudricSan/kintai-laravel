<?php

namespace App\Dashboard;

use App\Models\DashboardLayout;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Disposition affichée : disposition personnelle active > disposition du premier rôle de l'utilisateur qui en a une
 * (par `position`) > défauts du registre. Les widgets inconnus (bundle retiré) ou non autorisés sont ignorés.
 */
class DashboardLayoutResolver
{
    public const SOURCE_PERSONAL = 'personal';

    public const SOURCE_ROLE = 'role';

    public const SOURCE_DEFAULT = 'default';

    public function __construct(
        private readonly DashboardWidgetRegistry $registry,
        private readonly DashboardScope $scope,
    ) {}

    /**
     * Le widget est-il proposé à cet utilisateur (permission, fonctionnalité d'au moins un magasin visible) ?
     */
    public function isAvailable(User $user, DashboardWidget $widget): bool
    {
        if ($widget->permission !== null && ! Gate::forUser($user)->allows($widget->permission)) {
            return false;
        }

        if ($widget->storeFeature === null) {
            return true;
        }

        foreach ($this->scope->storesFor($user, $widget) as $store) {
            if ($store->hasFeature($widget->storeFeature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Widgets proposés à l'utilisateur sur ce tableau de bord, dans l'ordre du registre.
     *
     * @return list<DashboardWidget>
     */
    public function available(User $user, string $audience): array
    {
        return array_values(array_filter(
            $this->registry->forAudience($audience),
            fn (DashboardWidget $widget): bool => $this->isAvailable($user, $widget),
        ));
    }

    /**
     * @return list<DashboardPlacement>
     */
    public function resolve(User $user, string $audience): array
    {
        $layout = $this->layoutFor($user, $audience);

        if ($layout === null) {
            return $this->defaults($user, $audience);
        }

        return $this->placements($user, $audience, $layout->layout);
    }

    /**
     * @return self::SOURCE_*
     */
    public function sourceOf(User $user, string $audience): string
    {
        $layout = $this->layoutFor($user, $audience);

        return match (true) {
            $layout === null => self::SOURCE_DEFAULT,
            $layout->user_id !== null => self::SOURCE_PERSONAL,
            default => self::SOURCE_ROLE,
        };
    }

    /**
     * @return list<DashboardPlacement>
     */
    public function defaults(User $user, string $audience): array
    {
        $placements = [];
        foreach ($this->available($user, $audience) as $widget) {
            if ($widget->default) {
                $placements[] = new DashboardPlacement($widget, $widget->size);
            }
        }

        return $placements;
    }

    /**
     * Place les widgets d'une disposition enregistrée, en ignorant ceux qui sont inconnus ou non autorisés.
     *
     * @param  array<int, mixed>  $layout
     * @return list<DashboardPlacement>
     */
    public function placements(User $user, string $audience, array $layout): array
    {
        $placements = [];
        $seen = [];

        foreach ($layout as $entry) {
            if (! is_array($entry) || ! isset($entry['widget']) || ! is_string($entry['widget'])) {
                continue;
            }

            $widget = $this->registry->get($entry['widget']);
            if ($widget === null || $widget->audience !== $audience || isset($seen[$widget->key]) || ! $this->isAvailable($user, $widget)) {
                continue;
            }

            $seen[$widget->key] = true;
            $placements[] = new DashboardPlacement($widget, $this->sizeOf($widget, $entry['size'] ?? null));
        }

        return $placements;
    }

    /**
     * Disposition à enregistrer : widgets connus de l'audience, sans doublon, tailles valides. La disponibilité pour
     * l'utilisateur n'est pas testée (la disposition d'un rôle vaut pour d'autres utilisateurs) : elle l'est à l'affichage.
     *
     * @param  array<int, mixed>  $layout
     * @return list<array{widget: string, size?: string}>
     */
    public function normalize(string $audience, array $layout): array
    {
        $normalized = [];
        $seen = [];

        foreach ($layout as $entry) {
            if (! is_array($entry) || ! isset($entry['widget']) || ! is_string($entry['widget'])) {
                continue;
            }

            $widget = $this->registry->get($entry['widget']);
            if ($widget === null || $widget->audience !== $audience || isset($seen[$widget->key])) {
                continue;
            }

            $seen[$widget->key] = true;
            $size = $this->sizeOf($widget, $entry['size'] ?? null);
            $normalized[] = $size === $widget->size ? ['widget' => $widget->key] : ['widget' => $widget->key, 'size' => $size->value];
        }

        return $normalized;
    }

    /**
     * Disposition personnelle active, sinon celle du premier rôle qui en a une.
     */
    public function layoutFor(User $user, string $audience): ?DashboardLayout
    {
        $personal = DashboardLayout::query()
            ->personal($user, $audience)
            ->where('is_active', true)
            ->orderBy('id')
            ->first();

        if ($personal !== null) {
            return $personal;
        }

        $roleIds = DB::table('role_user')->where('user_id', $user->id)->distinct()->pluck('role_id')->all();
        if ($roleIds === []) {
            return null;
        }

        return DashboardLayout::query()
            ->whereNull('user_id')
            ->whereIn('role_id', $roleIds)
            ->where('dashboard', $audience)
            ->where('is_active', true)
            ->orderBy('position')
            ->orderBy('id')
            ->first();
    }

    private function sizeOf(DashboardWidget $widget, mixed $requested): WidgetSize
    {
        if ($widget->resizable && is_string($requested) && ($size = WidgetSize::tryFrom($requested)) !== null) {
            return $size;
        }

        return $widget->size;
    }
}
