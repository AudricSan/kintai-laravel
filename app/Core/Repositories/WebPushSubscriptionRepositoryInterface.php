<?php

declare(strict_types=1);

namespace kintai\Core\Repositories;

/** Abonnements web push (un par navigateur et par appareil). Champs : id, user_id, endpoint, p256dh, auth, user_agent, created_at, last_used_at. */
interface WebPushSubscriptionRepositoryInterface
{
    /** @return array[] */
    public function findByUser(int $userId): array;

    /**
     * Abonnements de plusieurs utilisateurs en une requête (envoi groupé).
     *
     * @param list<int> $userIds
     * @return array<int, array[]> utilisateur => abonnements
     */
    public function findByUsers(array $userIds): array;

    public function findByEndpoint(string $endpoint): ?array;

    /**
     * Enregistre l'abonnement ou le met à jour (même adresse). Une adresse déjà enregistrée pour un autre compte
     * passe à celui-ci : le navigateur appartient à la personne qui s'y est connectée en dernier.
     */
    public function save(int $userId, string $endpoint, string $p256dh, string $auth, ?string $userAgent): array;

    /** @param int|null $userId si fourni, ne supprime que l'abonnement de CET utilisateur */
    public function deleteByEndpoint(string $endpoint, ?int $userId = null): int;

    public function deleteById(int $id): void;

    public function touchLastUsed(int $id): void;

    public function countAll(): int;

    /**
     * Purge : supprime les abonnements inutilisés depuis cette date (last_used_at, ou created_at quand aucun envoi
     * n'a encore réussi) : adresses mortes que le service de push n'a jamais signalées expirées (404 / 410).
     *
     * @return int nombre d'abonnements supprimés
     */
    public function purgeUnusedSince(string $before): int;

    /** Supprime tous les abonnements de l'utilisateur (suppression du compte, SQLite n'applique pas les cascades). */
    public function deleteAllForUser(int $userId): void;
}
