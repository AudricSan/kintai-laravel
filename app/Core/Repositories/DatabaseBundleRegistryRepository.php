<?php
declare(strict_types=1);

namespace kintai\Core\Repositories;

use kintai\Domain\Eloquent\BundleRegistry as EloquentBundleRegistry;

final class DatabaseBundleRegistryRepository implements BundleRegistryRepositoryInterface
{
    public function __construct() {}

    public function all(): array
    {
        return EloquentBundleRegistry::orderBy('id')->get()->map(
            fn(EloquentBundleRegistry $row) => $this->toArray($row),
        )->all();
    }

    public function find(int $id): ?array
    {
        $row = EloquentBundleRegistry::find($id);
        return $row ? $this->toArray($row) : null;
    }

    public function existsByUrl(string $url): bool
    {
        return EloquentBundleRegistry::where('url', $url)->exists();
    }

    public function create(string $name, string $url): array
    {
        $row = EloquentBundleRegistry::create([
            'name'        => $name,
            'url'         => $url,
            'is_official' => false,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);

        return $this->toArray($row);
    }

    public function delete(int $id): void
    {
        EloquentBundleRegistry::where('id', $id)->delete();
    }

    /** @return array{id: int, name: string, url: string, is_official: bool} */
    private function toArray(EloquentBundleRegistry $row): array
    {
        return [
            'id'          => (int) $row->id,
            'name'        => (string) $row->name,
            'url'         => (string) $row->url,
            'is_official' => (bool) $row->is_official,
        ];
    }
}
