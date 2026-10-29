<?php

declare(strict_types=1);

namespace App\Auth;

use Illuminate\Support\ServiceProvider;
use Illuminate\Container\Container;
use App\Repositories\UserRepositoryInterface;
use App\Repositories\StoreUserRepositoryInterface;
use App\Repositories\StoreRepositoryInterface;
use App\Repositories\RoleRepositoryInterface;
use App\Repositories\RoleAssignmentRepositoryInterface;

final class AuthServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Connexion par mot de passe ou par cookie « rester connecté » : la fenêtre de rappel s'affiche une fois.
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Auth\Events\Login::class, fn() => $this->app->make(AuthService::class)->flagPasswordReminder());
        // « Rester connecté » : 30 jours.
        \Illuminate\Support\Facades\Auth::guard()->setRememberDuration(30 * 24 * 60);
    }

    public function register(): void
    {
        $this->app->singleton(
            AuthService::class,
            fn(Container $c) => new AuthService(
                $c->make(UserRepositoryInterface::class),
                $c->make(StoreUserRepositoryInterface::class),
                $c->make(StoreRepositoryInterface::class),
                $c->make(RoleRepositoryInterface::class),
                $c->make(RoleAssignmentRepositoryInterface::class),
            )
        );

        $this->app->singleton(
            PermissionService::class,
            fn(Container $c) => new PermissionService(
                $c->make(RoleAssignmentRepositoryInterface::class),
                $c->make(RoleRepositoryInterface::class),
            )
        );
    }
}
