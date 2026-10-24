<?php

declare(strict_types=1);

namespace kintai\Contract\Extension;

/**
 * Section du formulaire de shift fournie par un bundle (point d'extension `Bundle::shiftFormSections()`, contrat 1.17) :
 * par exemple les champs « Shift ouvert » et les boutons « Publier / Fermer la bourse » de shift-claim.
 *
 * Le Core rend la vue `view` (vue namespacée du bundle) dans le formulaire de shift — page de création, page
 * d'édition et fenêtre de la frise —, avec les variables `shift` (ligne de la table shifts, partielle en création),
 * `mode` (« create » ou « edit ») et `BASE_URL`. En édition, il rend aussi `actionsView` (facultative) dans la barre
 * d'actions, à côté de « Enregistrer » : ses boutons sont dans le formulaire du shift, ils visent donc leur propre
 * route avec `formaction` / `formmethod` (jamais de `<form>` imbriqué). À l'enregistrement, il écrit dans le shift les
 * champs `fields` reçus, et seulement eux : seules les colonnes ouvertes aux bundles
 * (ShiftFormSections::WRITABLE_SHIFT_COLUMNS) sont acceptées. Avec `storeFeature`, la section n'apparaît que si cette
 * fonctionnalité est active. Une exception au rendu retire la section et est journalisée, sans casser la page.
 */
final class ShiftFormSection
{
    /**
     * @param string       $key          Identifiant unique de la section
     * @param string       $view         Vue des champs (namespacée, ex. « shift-claim::shift-form-section »)
     * @param list<string> $fields       Colonnes de shifts enregistrées depuis le formulaire
     * @param int          $order        Ordre parmi les sections de bundles
     * @param string|null  $actionsView  Vue des boutons de la barre d'actions (édition seulement)
     * @param string|null  $storeFeature Fonctionnalité par magasin requise pour afficher la section
     */
    public function __construct(
        public readonly string $key,
        public readonly string $view,
        public readonly array $fields = [],
        public readonly int $order = 100,
        public readonly ?string $actionsView = null,
        public readonly ?string $storeFeature = null,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{1,40}$/', $key) !== 1) {
            throw new \InvalidArgumentException("ShiftFormSection : clé invalide « {$key} » (minuscules, chiffres, _).");
        }
        foreach ($fields as $field) {
            if (!is_string($field) || preg_match('/^[a-z][a-z0-9_]{1,40}$/', $field) !== 1) {
                throw new \InvalidArgumentException("ShiftFormSection « {$key} » : champ invalide.");
            }
        }
    }
}
