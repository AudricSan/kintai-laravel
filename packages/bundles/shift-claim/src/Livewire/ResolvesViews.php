<?php

namespace Kintai\Bundles\ShiftClaim\Livewire;

use Flux\Flux;
use Illuminate\Validation\ValidationException;
use Kintai\Bundles\ShiftClaim\ShiftClaimServiceProvider;

/**
 * Nom d'une vue du bundle. Les vues n'existent dans l'espace de noms du bundle que s'il est activé : l'analyse
 * statique, qui le voit désactivé, ne doit pas les prendre pour des vues manquantes.
 */
trait ResolvesViews
{
    protected function viewName(string $name): string
    {
        return ShiftClaimServiceProvider::SLUG.'::'.$name;
    }

    /**
     * Un refus d'état ou de règle (shift déjà attribué, candidature déjà traitée...) s'affiche en message.
     */
    protected function toastFailure(ValidationException $e): void
    {
        $messages = [];
        foreach ($e->errors() as $errors) {
            array_push($messages, ...$errors);
        }

        Flux::toast(variant: 'danger', text: implode(' ', array_unique($messages)));
    }
}
