<?php

namespace App\Authorization;

/**
 * Mot de passe provisoire donné à un compte créé ou réinitialisé sans mot de passe choisi.
 * Il est toujours accompagné de `must_change_password`.
 */
final class PasswordDefaults
{
    public const DEFAULT_PASSWORD = '0000';
}
