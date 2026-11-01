<?php

declare(strict_types=1);

namespace App\Auth;

use Illuminate\Support\ServiceProvider;
use Illuminate\Container\Container;
use App\Models\User;
use App\Models\StoreUser;
use App\Models\Store;
use App\Models\Role;
use App\Models\RoleAssignment;

final class AuthServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Connexion par mot de passe ou par cookie « rester connecté » : la fenêtre de rappel s'affiche une fois.
        // Pilote « kintai » de config/auth.php : connexion par e-mail ou par code employé + magasin.
        \Illuminate\Support\Facades\Auth::provider('kintai', fn($app, array $config) => new KintaiUserProvider($app['hash'], $config['model']));
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Auth\Events\Login::class, fn() => $this->app->make(AuthService::class)->flagPasswordReminder());
        // « Rester connecté » : 30 jours.
        \Illuminate\Support\Facades\Auth::guard()->setRememberDuration(30 * 24 * 60);
    }

    public function register(): void
    {
        $this->app->singleton(
            AuthService::class,
            fn(Container $c) => new AuthService(
            )
        );

        $this->app->singleton(
            PermissionService::class,
            fn(Container $c) => new PermissionService(
            )
        );
    }
}
