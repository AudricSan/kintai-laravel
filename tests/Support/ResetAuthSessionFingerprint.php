<?php

declare(strict_types=1);

namespace Tests\Support;

use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * $_SESSION est global au processus PHPUnit. AuthService y range l'empreinte du hash de mot de
 * passe (auth_pw_fp) à la connexion ou à la première requête d'une session : sans remise à zéro,
 * cette empreinte d'un test fuit vers le suivant, dont l'utilisateur simulé a un autre hash, et
 * la session y serait révoquée à tort. Chaque test repart donc sans empreinte, et sans RequestMemo.
 */
final class ResetAuthSessionFingerprint implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscriber(new class implements PreparationStartedSubscriber {
            public function notify(PreparationStarted $event): void
            {
                // Certains tests remplacent le conteneur global par un conteneur vide : on remet l application.
                $app = $GLOBALS["kintai_test_app"];
                \Illuminate\Container\Container::setInstance($app);
                \Illuminate\Support\Facades\Facade::setFacadeApplication($app);
                $app->make("session")->flush();
                // Les tests de routes installent un routeur vierge (kintai_test_router()) : on remet celui de l'application.
                $app->instance('router', $GLOBALS['kintai_test_router']);
                // Sans résolveur de segments (store, employee), les URL gardent les identifiants nus ; un test de routes
                // typées installe le sien.
                $app->instance(\App\Routing\StoreRouteBinder::class, new \Tests\Support\BareRouteBinder());
                $app->instance(\App\Routing\EmployeeRouteBinder::class, new \Tests\Support\BareRouteBinder());
                \Illuminate\Support\Facades\Route::clearResolvedInstance('router');
                // Aucun texte par défaut : __() rend la clé (voir kintai_test_translator()).
                kintai_test_translator_reset();
                // Même raison pour les lectures de comptes et de droits gardées le temps d'une requête.
                \App\Database\RequestMemo::flush();
            }
        });
    }
}
