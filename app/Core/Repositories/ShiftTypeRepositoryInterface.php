<?php

declare(strict_types=1);


namespace kintai\Core\Repositories;

interface ShiftTypeRepositoryInterface
{
    /**
     * Trouve un type de shift par son ID.
     */
    public function findById(int $id): ?array;

    /**
     * Retourne tous les types de shifts activés pour un store.
     */
    public function findByStore(int $storeId): array;

    /**
     * Retourne tous les types de shifts activés pour au moins un des stores donnés
     * (une seule occurrence par type même s'il couvre plusieurs de ces stores).
     */
    public function findByStores(array $storeIds): array;

    /**
     * Retourne les types de shifts actifs (is_active) d'un store.
     */
    public function findActive(int $storeId): array;

    /**
     * Retourne tous les types de shifts (sans filtre).
     */
    public function findAll(): array;

    /**
     * Crée ou met à jour un type de shift (hors affectation aux stores, voir syncStores).
     */
    public function save(array $data): array;

    /**
     * Supprime un type de shift par son ID (et ses affectations de stores).
     * @return int Nombre de lignes supprimées (0 ou 1).
     */
    public function delete(int $id): int;

    /**
     * Liste des ID de stores auxquels ce type de shift est affecté.
     */
    public function getStoreIds(int $shiftTypeId): array;

    /**
     * Remplace l'ensemble des stores affectés à ce type de shift.
     */
    public function syncStores(int $shiftTypeId, array $storeIds): void;

    /**
     * Indique si ce type de shift est activé pour le store donné.
     */
    public function isEnabledForStore(int $shiftTypeId, int $storeId): bool;

    /**
     * Active ce type de shift pour un store (idempotent).
     */
    public function enableForStore(int $shiftTypeId, int $storeId): void;

    /**
     * Désactive ce type de shift pour un store (idempotent).
     */
    public function disableForStore(int $shiftTypeId, int $storeId): void;
}
