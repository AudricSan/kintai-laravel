<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Vérifie qu'une fonctionnalité est activée pour le store de l'employé courant
 * (store_features). Extrait de l'ancien EmployeeController::assertFeature(),
 * partagé par les contrôleurs employé des bundles TimeOff/ShiftSwap/ShiftClaim/
 * Timeclock qui remplacent progressivement ses méthodes.
 *
 * Nécessite sur la classe utilisatrice : $this->storeUsers
 * (StoreUserRepositoryInterface), $this->stores (StoreRepositoryInterface),
 * et le trait HasBaseUrl (base()).
 */
trait HasStoreFeatureCheck
{
    /**
     * Si la fonctionnalité est désactivée pour le store de l'employé, retourne
     * une redirection vers le dashboard (l'employé n'a de toute façon jamais dû
     * arriver ici via la nav, qui masque déjà l'onglet correspondant). Retourne
     * null si l'accès est autorisé.
     */
    protected function assertFeature(Request $request, string $feature): ?Response
    {
        $user        = $request->attributes->get('auth_user');
        $memberships = $this->storeUsers->findByUser((int) ($user['id'] ?? 0));
        if (empty($memberships)) {
            return null;
        }
        $features = $this->stores->getFeatures((int) $memberships[0]['store_id']);
        if ($features === [] || in_array($feature, $features, true)) {
            return null;
        }
        return redirect()->away($this->base() . '/employee?error=feature_disabled');
    }
}
