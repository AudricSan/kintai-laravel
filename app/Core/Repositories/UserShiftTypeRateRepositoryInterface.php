<?php

declare(strict_types=1);


namespace kintai\Core\Repositories;

interface UserShiftTypeRateRepositoryInterface
{
    public function findById(int $id): ?array;

    /** Retourne tous les taux pour un utilisateur donné. */
    public function findByUser(int $userId): array;

    /** Retourne tous les taux pour un type de shift donné. */
    public function findByShiftType(int $shiftTypeId): array;

    /** Trouve le taux personnalisé pour un couple utilisateur/type de shift. */
    public function findRate(int $userId, int $shiftTypeId): ?array;

    /** Crée ou met à jour un taux. */
    public function save(array $data): array;

    /** Supprime un taux par son ID. */
    public function delete(int $id): int;
}
