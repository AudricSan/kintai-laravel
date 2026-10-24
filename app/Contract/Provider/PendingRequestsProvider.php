<?php

declare(strict_types=1);

namespace kintai\Contract\Provider;

/**
 * Fournisseur de demandes en attente (contrat 1.9), implémenté par un bundle et déclaré par
 * `Bundle::pendingRequestsProviders()` : demandes de congé, d'échange de shift, candidatures…
 *
 * Le Core s'en sert pour la page « Demandes » (une section par fournisseur), le compteur « Demandes en attente » du
 * tableau de bord et les alertes de demandes anciennes, sans connaître les tables du bundle. Un fournisseur en échec
 * (exception) est ignoré et journalisé.
 */
interface PendingRequestsProvider
{
    /** Identifiant unique du type de demande (ex. « timeoff »). */
    public function key(): string;

    /** Clé de traduction du titre de la section (ex. « pending_timeoff »). */
    public function label(): string;

    /** Clé de traduction affichée quand il n'y a aucune demande (ex. « no_pending_request »). */
    public function emptyLabel(): string;

    /** Permission requise pour voir ces demandes (ex. « timeoff.view »). */
    public function permission(): string;

    /** Fonctionnalité par magasin associée (ex. « timeoff »), ou null. */
    public function storeFeature(): ?string;

    /** Ordre de la section sur la page « Demandes ». */
    public function order(): int;

    /** Page où ces demandes sont traitées (lien « Tout voir »). */
    public function manageUrl(): string;

    /**
     * Demandes en attente des magasins donnés, dans l'ordre où elles doivent être affichées.
     *
     * @param list<int>|null $storeIds null = tous les magasins
     * @return list<PendingRequest>
     */
    public function pending(?array $storeIds): array;
}
