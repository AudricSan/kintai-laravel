<?php

declare(strict_types=1);

namespace kintai\Contract\Extension;

/**
 * Section du formulaire « Mes informations » du profil fournie par un bundle (point d'extension
 * `Bundle::profileSections()`, contrat 1.12) : par exemple les cases « Apparaître dans l'annuaire » de team-directory.
 *
 * Le Core rend la vue `view` (vue namespacée du bundle) dans le formulaire du profil, avant le bouton
 * « Enregistrer », avec la variable `user` (l'utilisateur connecté, ligne de la table users). À l'enregistrement, il
 * écrit les cases `flags` (0 ou 1) dans la table users, et seulement elles : une case absente de la requête garde sa
 * valeur. Seules les colonnes ouvertes aux bundles (ProfileSections::WRITABLE_USER_COLUMNS) sont acceptées ; une
 * exception au rendu retire la section et est journalisée, sans casser la page.
 */
final class ProfileSection
{
    /**
     * @param string       $key   Identifiant unique de la section
     * @param string       $view  Vue rendue (namespacée, ex. « team-directory::profile-section »)
     * @param list<string> $flags Colonnes booléennes de users enregistrées par la section (cases à cocher)
     * @param int          $order Ordre parmi les sections de bundles
     */
    public function __construct(
        public readonly string $key,
        public readonly string $view,
        public readonly array $flags = [],
        public readonly int $order = 100,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{1,40}$/', $key) !== 1) {
            throw new \InvalidArgumentException("ProfileSection : clé invalide « {$key} » (minuscules, chiffres, _).");
        }
        foreach ($flags as $flag) {
            if (!is_string($flag) || preg_match('/^[a-z][a-z0-9_]{1,40}$/', $flag) !== 1) {
                throw new \InvalidArgumentException("ProfileSection « {$key} » : case invalide.");
            }
        }
    }
}
