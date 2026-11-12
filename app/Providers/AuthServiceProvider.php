<?php

namespace App\Providers;

use App\Authorization\Authorizer;
use App\Authorization\CorePermissions;
use App\Authorization\DefaultPermissionRegistry;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Kintai\Contracts\Authorization\PermissionRegistry;

class AuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PermissionRegistry::class, DefaultPermissionRegistry::class);
        $this->app->scoped(Authorizer::class);
    }

    public function boot(): void
    {
        CorePermissions::registerIn($this->app->make(PermissionRegistry::class));

        // Réservée à l'Owner : Gate::before l'accorde, tous les autres sont refusés.
        Gate::define('manage-roles', fn (): bool => false);
        // Réglages de l'instance, maintenance, clés Web Push : même règle.
        Gate::define('manage-settings', fn (): bool => false);

        // Pas de Gate::define par clé : les bundles enregistrent leurs catégories plus tard dans le registre.
        Gate::before(function (User $user, string $ability, array $arguments): ?bool {
            $authorizer = $this->app->make(Authorizer::class);

            if ($authorizer->isOwner($user)) {
                return true;
            }

            if ($this->app->make(PermissionRegistry::class)->has($ability)) {
                return $authorizer->allows($user, $ability, $this->resolveStore($arguments[0] ?? null));
            }

            return null;
        });
    }

    /**
     * Magasin visé par le premier argument : un magasin, un identifiant, ou un modèle portant `store_id`.
     */
    private function resolveStore(mixed $argument): ?int
    {
        return match (true) {
            $argument instanceof Store => $argument->id,
            is_int($argument) => $argument,
            $argument instanceof Model => is_numeric($storeId = $argument->getAttribute('store_id')) ? (int) $storeId : null,
            default => null,
        };
    }
}
