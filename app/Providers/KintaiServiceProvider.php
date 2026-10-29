<?php

declare(strict_types=1);

namespace kintai\Providers;

use Illuminate\Console\Application as Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use kintai\Console\KintaiScriptCommand;
use kintai\Foundation\Auth\KintaiGate;
use kintai\Foundation\Auth\KintaiGuard;
use kintai\Foundation\Bridge\KintaiTranslator;

/**
 * Raccorde Laravel aux services de Kintai pendant la migration : les helpers de Laravel (__(), csrf_token(),
 * csrf_field(), session(), auth()) et le Gate rendent ce que rendaient les services de Kintai, et les scripts de
 * scripts/ deviennent des commandes Artisan.
 *
 * Enregistrés comme instances : un fournisseur différé de Laravel (traductions) ne les remplace pas ensuite.
 */
final class KintaiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->instance('translator', new KintaiTranslator());
    }

    public function boot(): void
    {
        // auth() et le Gate de Laravel lisent l'utilisateur et les permissions de Kintai.
        Auth::extend('kintai', static fn(): KintaiGuard => new KintaiGuard());
        Gate::before(KintaiGate::before(...));

        // Les scripts de scripts/ sont aussi des commandes Artisan (php artisan kintai:cron backup…).
        Artisan::starting(static function (Artisan $artisan): void {
            foreach (KintaiScriptCommand::SCRIPTS as $name => [$script, $description]) {
                $artisan->add(new KintaiScriptCommand($name, $script, $description));
            }
        });
    }
}
