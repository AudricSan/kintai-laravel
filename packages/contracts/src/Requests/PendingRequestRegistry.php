<?php

namespace Kintai\Contracts\Requests;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Point d'extension : les bundles qui gèrent des demandes (congés, échanges...) y déclarent un fournisseur, dans le
 * `boot()` de leur ServiceProvider :
 *
 *     $this->app->make(PendingRequestRegistry::class)->register(new PendingRequestProvider(
 *         key: 'timeoff', label: 'Time off', route: 'timeoff.index',
 *         count: fn (Authenticatable $user): int => TimeOffRequest::pendingFor($user)->count(),
 *     ));
 *
 * La page « Demandes » du Core, le widget compteur du tableau de bord et l'entrée de menu s'appuient sur ce registre.
 * Sans fournisseur, la page affiche un état vide et l'entrée de menu disparaît.
 */
interface PendingRequestRegistry
{
    /**
     * @throws \InvalidArgumentException Clé déjà enregistrée.
     */
    public function register(PendingRequestProvider $provider): void;

    public function isEmpty(): bool;

    /**
     * @return list<PendingRequestProvider>
     */
    public function providers(): array;

    /**
     * Compteur de chaque fournisseur, dans l'ordre d'enregistrement. Un fournisseur défaillant compte 0 (journalisé) :
     * il ne doit pas casser la page ni les autres compteurs.
     *
     * @return list<array{provider: PendingRequestProvider, count: int}>
     */
    public function counts(Authenticatable $user): array;

    public function total(Authenticatable $user): int;
}
