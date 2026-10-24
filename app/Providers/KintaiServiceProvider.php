<?php

declare(strict_types=1);

namespace kintai\Providers;

use Illuminate\Support\ServiceProvider;
use kintai\Foundation\Bridge\KintaiTranslator;
use kintai\Foundation\Bridge\NativeSessionStore;

/**
 * Raccorde Laravel aux services de Kintai pendant la migration : les helpers de Laravel (__(), csrf_token(),
 * csrf_field(), session()) rendent ce que rendaient ceux de Kintai.
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
}
