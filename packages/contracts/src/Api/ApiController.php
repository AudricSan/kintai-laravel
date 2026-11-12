<?php

namespace Kintai\Contracts\Api;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Base des contrôleurs de l'API d'un bundle (`routes/api.php`, voir `BundleServiceProvider::API_MIDDLEWARE`).
 *
 * Les erreurs suivent le format de l'API du Core sans que le bundle fasse rien : une validation qui échoue donne 422
 * `validation_failed`, `abort(404)` ou un modèle introuvable 404 `not_found`, un refus (`AuthorizationException`,
 * `abort(403)`) 403 `forbidden`, et `ApiException` porte un code propre. Les listes se paginent avec `paginate($this->perPage($request))`
 * et se rendent avec une `JsonResource` : même enveloppe (`data`, `links`, `meta`) que le Core.
 */
abstract class ApiController extends Controller
{
    /**
     * Utilisateur authentifié par jeton.
     */
    protected function actor(Request $request): Authenticatable
    {
        return $request->user('sanctum') ?? throw new AuthenticationException;
    }

    /**
     * Taille de page demandée (`per_page`, de 1 à 100, 20 par défaut).
     */
    protected function perPage(Request $request): int
    {
        return min(max((int) $request->query('per_page', 20), 1), 100);
    }
}
