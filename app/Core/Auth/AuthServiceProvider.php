<?php

declare(strict_types=1);

namespace kintai\Core\Auth;

use Illuminate\Support\ServiceProvider;
use Illuminate\Container\Container;
use kintai\Core\Repositories\UserRepositoryInterface;
use kintai\Core\Repositories\StoreUserRepositoryInterface;
use kintai\Core\Repositories\StoreRepositoryInterface;
use kintai\Core\Repositories\RoleRepositoryInterface;
use kintai\Core\Repositories\RoleAssignmentRepositoryInterface;
use kintai\Core\Repositories\RememberTokenRepositoryInterface;

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
