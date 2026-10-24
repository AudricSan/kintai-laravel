<?php

declare(strict_types=1);


namespace kintai\Core\Repositories;

interface ShiftRepositoryInterface
{
    /**
     * Trouve un shift par son ID.
     */
    public function findById(int $id): ?array;

    /**
     * Retourne tous les shifts d'un store.
     */
    public function findByStore(int $storeId): array;

    /**
     * Shifts d'un store dont la date est comprise entre $from et $to inclus (YYYY-MM-DD), supprimés compris
     * (même périmètre que findByStore(), restreint par date côté base plutôt qu'en PHP).
     */
    public function findByStoreBetween(int $storeId, string $from, string $to): array;

    /**
     * Retourne tous les shifts d'un utilisateur.
     */
    public function findByUser(int $userId): array;

    /**
     * Retourne les shifts d'un store à une date donnée (YYYY-MM-DD).
     */
    public function findByDate(int $storeId, string $date): array;

    /**
     * Retourne les shifts d'un utilisateur dans un store à une date donnée.
     */
    public function findByUserAndDate(int $userId, int $storeId, string $date): array;

    /**
     * Retourne tous les shifts (toutes tables, sans filtre).
     */
    public function findAll(): array;

    /**
     * Retourne les shifts d'une plage de dates (bornes incluses, YYYY-MM-DD), triés par date puis id.
     * $storeIds = null : tous les magasins ; tableau vide : aucun résultat.
     */
    public function findBetween(?array $storeIds, string $from, string $to): array;

    /**
     * Retourne tous les shifts pour une date donnée (YYYY-MM-DD), tous stores confondus.
     */
    public function findAllByDate(string $date): array;

    /**
     * Retourne les shifts ouverts à la bourse (is_open = 1).
     * Si $storeId est fourni, filtre par store.
     */
    public function findOpen(?int $storeId = null): array;

    /**
     * Crée ou met à jour un shift.
     */
    public function save(array $data): array;

    /**
     * Attribue le shift à $userId et ferme la bourse (is_open = 0), mais
     * uniquement si le shift est encore ouvert au moment de l'écriture
     * (condition portée par l'UPDATE lui-même, pas par une lecture préalable) —
     * empêche deux approbations concurrentes de la bourse de s'écraser
     * mutuellement. Retourne le shift à jour, ou null si le shift n'était déjà
     * plus ouvert (quelqu'un d'autre a gagné la course).
     */
    public function closeOpenShiftTo(int $id, int $userId): ?array;

    /**
     * Supprime un shift par son ID (hard delete — voir shift_deletion_log pour la
     * trace conservée à des fins de synchronisation iCal).
     * @return int Nombre de lignes supprimées (0 ou 1).
     */
    public function delete(int $id): int;

    /**
     * Retourne les suppressions récentes (depuis $since, format Y-m-d H:i:s) de shifts
     * d'un utilisateur dans un store, sous forme de tableaux façon "shift" avec
     * deleted_at renseigné — utilisé par IcalController pour émettre les VEVENT
     * STATUS:CANCELLED correspondants dans le flux de l'employé.
     */
    public function findRecentDeletionsByUserAndStore(int $userId, int $storeId, string $since): array;
}
