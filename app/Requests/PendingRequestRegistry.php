<?php

namespace App\Requests;

use App\Models\User;
use InvalidArgumentException;
use Throwable;

/**
 * Point d'extension : les bundles qui gèrent des demandes (congés, échanges...) y déclarent un fournisseur, dans le
 * `boot()` de leur ServiceProvider :
 *
 *     $this->app->make(PendingRequestRegistry::class)->register(new PendingRequestProvider(
 *         key: 'timeoff', label: 'Time off', route: 'timeoff.index',
 *         count: fn (User $user): int => TimeOffRequest::pendingFor($user)->count(),
 *     ));
 *
 * La page « Demandes » du Core, le widget compteur du tableau de bord et l'entrée de menu s'appuient sur ce registre.
 * Sans fournisseur, la page affiche un état vide et l'entrée de menu disparaît.
 */
class PendingRequestRegistry
{
    /** @var array<string, PendingRequestProvider> */
    private array $providers = [];

    public function register(PendingRequestProvider $provider): void
    {
        if (isset($this->providers[$provider->key])) {
            throw new InvalidArgumentException("Pending request provider [{$provider->key}] is already registered.");
        }

        $this->providers[$provider->key] = $provider;
    }

    public function isEmpty(): bool
    {
        return $this->providers === [];
    }

    /**
     * @return list<PendingRequestProvider>
     */
    public function providers(): array
    {
        return array_values($this->providers);
    }

    /**
     * Compteur de chaque fournisseur, dans l'ordre d'enregistrement. Un fournisseur défaillant compte 0 (journalisé) :
     * il ne doit pas casser la page ni les autres compteurs.
     *
     * @return list<array{provider: PendingRequestProvider, count: int}>
     */
    public function counts(User $user): array
    {
        $rows = [];

        foreach ($this->providers as $provider) {
            try {
                $count = $provider->countFor($user);
            } catch (Throwable $e) {
                report($e);
                $count = 0;
            }

            $rows[] = ['provider' => $provider, 'count' => $count];
        }

        return $rows;
    }

    public function total(User $user): int
    {
        return array_sum(array_column($this->counts($user), 'count'));
    }
}
