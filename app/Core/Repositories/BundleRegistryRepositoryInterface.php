<?php

declare(strict_types=1);

namespace kintai\Core\Repositories;

interface BundleRegistryRepositoryInterface
{
    /**
     * @return array<int, array{id: int, name: string, url: string, is_official: bool}>
     */
    public function all(): array;

    /**
     * @return array{id: int, name: string, url: string, is_official: bool}|null
     */
    public function find(int $id): ?array;

    public function existsByUrl(string $url): bool;

    /**
     * @return array{id: int, name: string, url: string, is_official: bool}
     */
    public function create(string $name, string $url): array;

    public function delete(int $id): void;
}
