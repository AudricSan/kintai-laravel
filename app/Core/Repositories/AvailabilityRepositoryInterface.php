<?php

declare(strict_types=1);


namespace kintai\Core\Repositories;

interface AvailabilityRepositoryInterface
{
    /**
     * Trouve une disponibilité par son ID.
     */
    public function findById(int $id): ?array;

    /**
     * Retourne toutes les disponibilités d'un utilisateur.
     */
    public function findByUser(int $userId): array;

    /**
     * Retourne toutes les disponibilités d'un store.
     */
    public function findByStore(int $storeId): array;

    /**
     * Retourne les disponibilités d'un utilisateur pour un store donné.
     */
    public function findByUserAndStore(int $userId, int $storeId): array;

    /**
     * Supprime toutes les disponibilités d'un utilisateur pour un store donné.
     */
    public function deleteByUserAndStore(int $userId, int $storeId): int;

    /**
     * Crée ou met à jour une disponibilité.
     */
    public function save(array $data): array;

    /**
     * Supprime une disponibilité par son ID.
     * @return int Nombre de lignes supprimées (0 ou 1).
     */
    public function delete(int $id): int;
}
