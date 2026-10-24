<?php

declare(strict_types=1);

namespace kintai\Core\Repositories;

/**
 * Écarts de l'utilisateur avec les valeurs par défaut des types de notification (canaux « push » et « email »).
 * Aucune ligne = valeur par défaut du type.
 */
interface NotificationPreferenceRepositoryInterface
{
    /** @return array<string, array<string, bool>> type => canal => activé, pour cet utilisateur */
    public function findByUser(int $userId): array;

    /**
     * Même chose pour plusieurs utilisateurs en une requête (envoi groupé, sans N+1).
     *
     * @param list<int> $userIds
     * @return array<int, array<string, array<string, bool>>> utilisateur => type => canal => activé
     */
    public function findByUsers(array $userIds): array;

    public function set(int $userId, string $type, string $channel, bool $enabled): void;

    /** Revient à la valeur par défaut du type pour ce canal. */
    public function reset(int $userId, string $type, string $channel): void;

    /** Supprime toutes les préférences de l'utilisateur (suppression du compte, SQLite n'applique pas les cascades). */
    public function deleteAllForUser(int $userId): void;
}
