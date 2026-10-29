<?php

declare(strict_types=1);

namespace App\Database;

use Illuminate\Support\ServiceProvider;
use Illuminate\Database\Capsule\Manager as Capsule;

final class DatabaseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registerEloquent();
    }

    private function registerEloquent(): void
    {
        $laravel = \Illuminate\Container\Container::getInstance();
        if ($laravel instanceof \Illuminate\Contracts\Foundation\Application && $laravel->bound('db') && $laravel->bound('config')) {
            // Application Laravel démarrée (web, Artisan) : sa connexion, configurée depuis les mêmes fichiers.
            $capsule = new LaravelCapsule($laravel);
            $capsule->setAsGlobal();
            $capsule->bootEloquent();
            // created_at dans le fuseau de l'instance plutôt que l'UTC de CURRENT_TIMESTAMP (voir InstanceTimestamps).
            $events = $laravel->make('events');
            \Illuminate\Database\Eloquent\Model::setEventDispatcher($events);
            if (!$laravel->bound(InstanceTimestamps::class)) {
                $timestamps = new InstanceTimestamps();
                $timestamps->listenOn($events);
                $laravel->instance(InstanceTimestamps::class, $timestamps);
            }
            $this->app->instance(Capsule::class, $capsule);
            return;
        }

    }
}
