<?php

declare(strict_types=1);

namespace kintai\Foundation;

use Illuminate\Foundation\Application as LaravelApplication;

/**
 * Application Laravel de Kintai.
 *
 * Différences avec celle de Laravel :
 * - les alias de classe du contrat de bundle se résolvent vers la classe réelle ({@see ResolvesClassAliases}).
 */
class Application extends LaravelApplication
{
    use ResolvesClassAliases;

}
