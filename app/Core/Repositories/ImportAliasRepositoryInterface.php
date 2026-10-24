<?php

declare(strict_types=1);

namespace kintai\Core\Repositories;

interface ImportAliasRepositoryInterface
{
    /** Retourne [staffName_lower => user_id] pour un magasin. */
    public function findByStore(int $storeId): array;

    /** Insère ou met à jour un alias. */
    public function upsert(int $storeId, string $staffName, int $userId): void;

    /** Supprime un alias (désassignation explicite). */
    public function deleteAlias(int $storeId, string $staffName): void;
}
