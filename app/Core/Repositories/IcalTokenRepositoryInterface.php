<?php

declare(strict_types=1);

namespace kintai\Core\Repositories;

interface IcalTokenRepositoryInterface
{
    public function findByToken(string $token): ?array;

    public function findByUserAndStore(int $userId, int $storeId): ?array;

    public function findByUser(int $userId): array;

    public function save(array $data): array;

    public function deleteByUserAndStore(int $userId, int $storeId): int;

    /**
     * Retourne le token existant pour ce user+store, ou le crée s'il n'existe pas
     * encore. Sûr en cas de concurrence : la contrainte unique (user_id, store_id)
     * en base peut faire échouer la création si deux requêtes arrivent en même
     * temps (ex. deux onglets ouverts) — dans ce cas on relit simplement le token
     * créé par l'autre requête au lieu de laisser planter l'appelant.
     */
    public function findOrCreateForUserAndStore(int $userId, int $storeId): array;
}
