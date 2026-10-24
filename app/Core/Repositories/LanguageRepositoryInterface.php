<?php

declare(strict_types=1);

namespace kintai\Core\Repositories;

interface LanguageRepositoryInterface
{
    public function findAll(): array;

    public function findAllActive(): array;

    public function findByCode(string $code): ?array;

    public function findDefault(): ?array;

    /** Upsert : si le code existe déjà, met à jour name/is_active/sort_order. */
    public function save(array $data): array;

    /** Bascule is_default sur $code (et le retire des autres). */
    public function setDefault(string $code): bool;

    public function toggleActive(string $code, bool $active): bool;

    public function delete(string $code): int;
}
