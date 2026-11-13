<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    /**
     * Par défaut aucun plafond ni restriction de bundle (palier gratuit levé) : les tests de la licence les posent eux-mêmes.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['kintai.license.free_limits' => [], 'kintai.license.free_bundles' => ['*']]);
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
