<?php

declare(strict_types=1);

namespace kintai\Core\Repositories;

interface InstalledBundleRepositoryInterface
{
    /**
     * @return array<int, array{slug: string, active_version: string, source_registry_url: ?string}>
     */
    public function all(): array;

    /**
     * @return array{slug: string, active_version: string, source_registry_url: ?string}|null
     */
    public function find(string $slug): ?array;

    public function upsert(string $slug, string $activeVersion, ?string $sourceRegistryUrl): void;

    public function delete(string $slug): void;
}
