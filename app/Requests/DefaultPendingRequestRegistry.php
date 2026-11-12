<?php

namespace App\Requests;

use Illuminate\Contracts\Auth\Authenticatable;
use InvalidArgumentException;
use Kintai\Contracts\Requests\PendingRequestProvider;
use Kintai\Contracts\Requests\PendingRequestRegistry;
use Throwable;

/**
 * Registre en mémoire (voir l'interface du contrat pour le mode d'emploi).
 */
class DefaultPendingRequestRegistry implements PendingRequestRegistry
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
    public function counts(Authenticatable $user): array
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

    public function total(Authenticatable $user): int
    {
        return array_sum(array_column($this->counts($user), 'count'));
    }
}
