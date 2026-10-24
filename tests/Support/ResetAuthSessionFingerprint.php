<?php

declare(strict_types=1);

namespace kintai\Tests\Support;

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
 * la session y serait révoquée à tort. Chaque test repart donc sans empreinte.
 */
final class ResetAuthSessionFingerprint implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscriber(new class implements PreparationStartedSubscriber {
            public function notify(PreparationStarted $event): void
            {
                unset($_SESSION['auth_pw_fp']);
            }
        });
    }
}
