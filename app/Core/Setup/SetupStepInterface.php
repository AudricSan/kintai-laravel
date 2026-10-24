<?php

declare(strict_types=1);

namespace kintai\Core\Setup;

use kintai\Core\Request;

/**
 * Une étape de configuration de l'assistant (/admin/setup) qui suit l'installateur. L'ordre des étapes, leur état
 * (faite, passée, à faire) et la navigation sont gérés par SetupWizard ; une étape ne sait que se présenter, enregistrer
 * sa saisie et se résumer. Les étapes « Bundles » et « Licence » ont leur propre écran (voir SetupWizardController).
 *
 * Tout passe par la session de l'Owner (OwnerOnlyMiddleware) et le jeton CSRF du formulaire.
 */
interface SetupStepInterface
{
    /** Identifiant stable (clé dans l'état de l'assistant, segment d'URL, nom de la vue system.setup.{id}). */
    public function id(): string;

    /** @return array<string, mixed> variables de la vue de l'étape */
    public function data(Request $request): array;

    /**
     * Enregistre la saisie. Rien n'est enregistré si une erreur est renvoyée.
     *
     * @return list<string> clés de traduction des erreurs, vide si tout est enregistré
     */
    public function handle(Request $request, int $userId): array;

    /** Ligne du récapitulatif : ce qui est configuré, ou null si l'étape n'a rien produit. */
    public function summary(): ?string;
}
