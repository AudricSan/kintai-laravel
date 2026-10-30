<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Models\ActivityEntry as EloquentActivityEntry;
use Illuminate\Database\Eloquent\Builder;

final class DatabaseLogRepository implements LogRepositoryInterface
{
    public function findAll(int $page = 1, int $perPage = 50, array $filters = []): array
    {
        $query = $this->buildFilterQuery($filters);

        $rows = $query->orderBy('created_at', 'desc')
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get()
            ->toArray();

        foreach ($rows as &$row) {
            if (isset($row['context']) && is_string($row['context'])) {
                $decoded = json_decode($row['context'], true);
                $row['context'] = $decoded ?? $row['context'];
            }
        }
        unset($row);

        return $rows;
    }

    public function countAll(array $filters = []): int
    {
        return $this->buildFilterQuery($filters)->count();
    }

    public function findById(int $id): ?array
    {
        $row = EloquentActivityEntry::find($id);
        if ($row === null) {
            return null;
        }
        $data = $row->toArray();
        if (isset($data['context']) && is_string($data['context'])) {
            $decoded = json_decode($data['context'], true);
            $data['context'] = $decoded ?? $data['context'];
        }
        return $data;
    }

    public function findChannels(): array
    {
        return EloquentActivityEntry::distinct()
            ->pluck('channel')
            ->filter()
            ->values()
            ->toArray();
    }

    public function findActions(): array
    {
        return EloquentActivityEntry::distinct()
            ->whereNotNull('action')
            ->pluck('action')
            ->filter()
            ->values()
            ->toArray();
    }

    public function findResourceTypes(): array
    {
        return EloquentActivityEntry::distinct()
            ->whereNotNull('resource_type')
            ->pluck('resource_type')
            ->filter()
            ->values()
            ->toArray();
    }

    public function purgeOlderThan(string $before): int
    {
        return EloquentActivityEntry::where('created_at', '<', $before)->delete();
    }

    public function purgeAll(): int
    {
        return EloquentActivityEntry::query()->delete();
    }

    public function reclaimSpace(): void
    {
        $model      = new EloquentActivityEntry();
        $connection = $model->getConnection();

        try {
            match ($connection->getDriverName()) {
                'sqlite' => $connection->statement('VACUUM'),
                'mysql'  => $connection->statement('OPTIMIZE TABLE ' . $connection->getTablePrefix() . $model->getTable()),
                default  => null,
            };
        } catch (\Throwable) {
            // Gain d'espace non essentiel : la purge, elle, a réussi.
        }
    }

    private function buildFilterQuery(array $filters): Builder
    {
        $query = EloquentActivityEntry::query();

        if (!empty($filters['level'])) {
            $query->where('level', $filters['level']);
        }
        if (!empty($filters['channel'])) {
            $query->where('channel', $filters['channel']);
        }
        if (!empty($filters['action'])) {
            $query->where('action', $filters['action']);
        }
        if (!empty($filters['resource_type'])) {
            $query->where('resource_type', $filters['resource_type']);
        }
        if (!empty($filters['user_id'])) {
            $query->where('user_id', (int) $filters['user_id']);
        }
        if (!empty($filters['store_id'])) {
            $query->where('store_id', (int) $filters['store_id']);
        }
        if (isset($filters['store_ids']) && is_array($filters['store_ids'])) {
            $query->whereIn('store_id', array_map('intval', $filters['store_ids']));
        }
        if (!empty($filters['from'])) {
            $query->where('created_at', '>=', $filters['from']);
        }
        if (!empty($filters['to'])) {
            $query->where('created_at', '<=', $filters['to'] . ' 23:59:59');
        }
        if (!empty($filters['query'])) {
            $q = $filters['query'];
            $query->where(function (Builder $sub) use ($q) {
                $sub->where('message', 'like', "%{$q}%")
                    ->orWhere('action', 'like', "%{$q}%")
                    ->orWhere('context', 'like', "%{$q}%");
            });
        }

        return $query;
    }
}
