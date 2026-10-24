<?php
declare(strict_types=1);

namespace kintai\Core\Repositories;

use kintai\Domain\Eloquent\InstalledBundle as EloquentInstalledBundle;

final class DatabaseInstalledBundleRepository implements InstalledBundleRepositoryInterface
{
    public function __construct() {}

    public function all(): array
    {
        return EloquentInstalledBundle::orderBy('slug')->get()->map(
            fn(EloquentInstalledBundle $row) => $this->toArray($row),
        )->all();
    }

    public function find(string $slug): ?array
    {
        $row = EloquentInstalledBundle::find($slug);
        return $row ? $this->toArray($row) : null;
    }

    public function upsert(string $slug, string $activeVersion, ?string $sourceRegistryUrl): void
    {
        EloquentInstalledBundle::updateOrCreate(
            ['slug' => $slug],
            ['active_version' => $activeVersion, 'source_registry_url' => $sourceRegistryUrl],
        );
    }

    public function delete(string $slug): void
    {
        EloquentInstalledBundle::where('slug', $slug)->delete();
    }

    /** @return array{slug: string, active_version: string, source_registry_url: ?string} */
    private function toArray(EloquentInstalledBundle $row): array
    {
        return [
            'slug'                 => (string) $row->slug,
            'active_version'       => (string) $row->active_version,
            'source_registry_url'  => $row->source_registry_url !== null ? (string) $row->source_registry_url : null,
        ];
    }
}
