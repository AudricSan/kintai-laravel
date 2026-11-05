<?php

namespace Kintai\Bundles\Messaging\Livewire;

use Flux\Flux;
use Illuminate\Validation\ValidationException;
use Kintai\Bundles\Messaging\MessagingServiceProvider;

/**
 * Nom d'une vue du bundle. Les vues n'existent dans l'espace de noms du bundle que s'il est activé : l'analyse
 * statique, qui le voit désactivé, ne doit pas les prendre pour des vues manquantes.
 */
trait ResolvesViews
{
    protected function viewName(string $name): string
    {
        return MessagingServiceProvider::SLUG.'::'.$name;
    }

    /**
     * Un refus de règle (magasin fermé à la messagerie...) s'affiche en message, jamais en page d'erreur.
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
