<?php

namespace Tests\Concerns;

use App\Models\User;
use Carbon\CarbonInterface;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Outils de test de l'API v1 : jetons réels (Sanctum), jamais de session.
 */
trait UsesApi
{
    /**
     * Chaque requête repart de gardes d'authentification vierges, comme un vrai processus par requête : sinon la garde
     * garderait l'utilisateur de la requête précédente.
     *
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $cookies
     * @param  array<string, mixed>  $files
     * @param  array<string, mixed>  $server
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app['auth']->forgetGuards();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    /**
     * Crée un jeton pour l'utilisateur et renvoie sa valeur en clair.
     */
    protected function tokenFor(User $user, string $name = 'Phone', ?CarbonInterface $expiresAt = null): string
    {
        return $user->createToken($name, ['*'], $expiresAt ?? now()->addDays(30))->plainTextToken;
    }

    /**
     * Les prochaines requêtes sont faites avec un jeton de cet utilisateur.
     */
    protected function actingWithToken(User $user): static
    {
        $this->flushHeaders();

        return $this->withToken($this->tokenFor($user));
    }

    /**
     * Le jeton enregistré à partir de sa valeur en clair.
     */
    protected function tokenRecord(string $plain): ?PersonalAccessToken
    {
        return PersonalAccessToken::findToken($plain);
    }
}
