<?php

declare(strict_types=1);

namespace kintai\Core;

abstract class ServiceProvider
{
    protected Container $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * Register services in the container.
     */
    abstract public function register(): void;

    /**
     * Perform any booting logic after all services are registered.
     */
    public function boot(): void
    {
        // Optional
    }
}
