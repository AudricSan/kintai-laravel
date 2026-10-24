<?php

declare(strict_types=1);

namespace kintai\Providers;

use Illuminate\Console\Application as Artisan;
use Illuminate\Support\ServiceProvider;
use kintai\Console\KintaiScriptCommand;
use kintai\Foundation\Bridge\KintaiTranslator;
use kintai\Foundation\Bridge\NativeSessionStore;

/**
 * Raccorde Laravel aux services de Kintai pendant la migration : les helpers de Laravel (__(), csrf_token(),
 * csrf_field(), session()) rendent ce que rendaient ceux de Kintai, et les scripts de scripts/ deviennent des
 * commandes Artisan.
 *
 * Enregistrés comme instances : un fournisseur différé de Laravel (traductions) ne les remplace pas ensuite.
 */
final class KintaiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->instance('translator', new KintaiTranslator());
        $this->app->instance('session', new NativeSessionStore());
    }

    public function boot(): void
    {
        // Les scripts de scripts/ sont aussi des commandes Artisan (php artisan kintai:cron backup…).
        Artisan::starting(static function (Artisan $artisan): void {
            foreach (KintaiScriptCommand::SCRIPTS as $name => [$script, $description]) {
                $artisan->add(new KintaiScriptCommand($name, $script, $description));
            }
        });
    }
}
