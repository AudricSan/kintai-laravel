<?php

namespace Tests\Fixtures\Bundles;

use Kintai\Contracts\Bundle\BundleServiceProvider;

/**
 * Bundle de test qui exige une autre version majeure du contrat : l'Owner ne doit pas pouvoir l'activer.
 */
class FutureBundleServiceProvider extends BundleServiceProvider
{
    public function slug(): string
    {
        return 'future';
    }

    public function name(): string
    {
        return 'Future bundle';
    }

    public function version(): string
    {
        return '3.0.0';
    }

    public function description(): string
    {
        return 'Needs a newer Kintai contract.';
    }

    public function requiredContract(): string
    {
        return '^2.0';
    }
}
