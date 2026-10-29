<?php

declare(strict_types=1);

namespace App\Foundation;

use Illuminate\Container\Container;

/** Conteneur Illuminate qui résout les alias de classe du contrat de bundle (voir {@see ResolvesClassAliases}). */
class AliasAwareContainer extends Container
{
    use ResolvesClassAliases;
}
