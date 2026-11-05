<?php

namespace Kintai\Bundles\Notebook\Livewire;

use Kintai\Bundles\Notebook\NotebookServiceProvider;

/**
 * Nom d'une vue du bundle. Les vues n'existent dans l'espace de noms du bundle que s'il est activé : l'analyse
 * statique, qui le voit désactivé, ne doit pas les prendre pour des vues manquantes.
 */
trait ResolvesViews
{
    private function viewName(string $name): string
    {
        return NotebookServiceProvider::SLUG.'::'.$name;
    }
}
