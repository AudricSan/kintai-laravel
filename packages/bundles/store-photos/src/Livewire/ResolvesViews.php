<?php

namespace Kintai\Bundles\StorePhotos\Livewire;

use Kintai\Bundles\StorePhotos\StorePhotosServiceProvider;

/**
 * Nom d'une vue du bundle. Les vues n'existent dans l'espace de noms du bundle que s'il est activé : l'analyse
 * statique, qui le voit désactivé, ne doit pas les prendre pour des vues manquantes.
 */
trait ResolvesViews
{
    protected function viewName(string $name): string
    {
        return StorePhotosServiceProvider::SLUG.'::'.$name;
    }
}
