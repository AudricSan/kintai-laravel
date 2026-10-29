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
use App\Repositories\RememberTokenRepositoryInterface;

final class AuthServiceProvider extends ServiceProvider
{
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
                $c->make(RememberTokenRepositoryInterface::class),
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
