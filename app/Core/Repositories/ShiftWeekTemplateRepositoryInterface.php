<?php

declare(strict_types=1);

namespace kintai\Core\Repositories;

/**
 * Modèles de semaine d'un magasin (voir WeekPlanner). Les entrées sont rendues décodées (tableau), jamais en JSON.
 */
interface ShiftWeekTemplateRepositoryInterface
{
    /** @return array{id: int, store_id: int, name: string, entries: list<array<string, mixed>>, created_by: ?int}|null */
    public function findById(int $id): ?array;

    /** Modèles du magasin, par nom. @return list<array{id: int, store_id: int, name: string, entries: list<array<string, mixed>>, created_by: ?int}> */
    public function findByStore(int $storeId): array;

    /**
     * Enregistre un modèle ; un modèle du même nom dans ce magasin est remplacé.
     *
     * @param list<array<string, mixed>> $entries
     * @return array{id: int, store_id: int, name: string, entries: list<array<string, mixed>>, created_by: ?int}
     */
    public function save(int $storeId, string $name, array $entries, ?int $createdBy): array;

    public function delete(int $id): int;
}
