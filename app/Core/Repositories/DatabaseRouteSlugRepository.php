<?php

declare(strict_types=1);

namespace kintai\Core\Repositories;

use kintai\Domain\Eloquent\RouteSlug;
use kintai\Domain\Eloquent\User as EloquentUser;

final class DatabaseRouteSlugRepository implements RouteSlugRepositoryInterface
{
    public function currentSlugs(string $type): array
    {
        return RouteSlug::where('entity_type', $type)
            ->where('is_current', true)
            ->pluck('slug', 'entity_id')
            ->map(fn($slug) => (string) $slug)
            ->all();
    }

    public function current(string $type, int $entityId): ?array
    {
        $row = RouteSlug::where('entity_type', $type)
            ->where('entity_id', $entityId)
            ->where('is_current', true)
            ->first();

        return $row ? $this->toArray($row) : null;
    }

    public function findBySlug(string $type, string $slug): ?array
    {
        $row = RouteSlug::where('entity_type', $type)->where('slug', $slug)->first();

        return $row ? $this->toArray($row) : null;
    }

    public function isTakenByOther(string $type, string $slug, int $exceptEntityId): bool
    {
        return RouteSlug::where('entity_type', $type)
            ->where('slug', $slug)
            ->where('entity_id', '!=', $exceptEntityId)
            ->exists();
    }

    public function setCurrent(string $type, int $entityId, string $slug, bool $manual): void
    {
        $this->releaseFromOthers($type, $slug, $entityId);

        RouteSlug::where('entity_type', $type)
            ->where('entity_id', $entityId)
            ->where('is_current', true)
            ->where('slug', '!=', $slug)
            ->update(['is_current' => false]);

        $existing = RouteSlug::where('entity_type', $type)
            ->where('entity_id', $entityId)
            ->where('slug', $slug)
            ->first();

        if ($existing !== null) {
            $existing->is_current = true;
            $existing->is_manual  = $manual;
            $existing->save();
            return;
        }

        RouteSlug::create([
            'entity_type' => $type,
            'entity_id'   => $entityId,
            'slug'        => $slug,
            'is_current'  => true,
            'is_manual'   => $manual,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
    }

    public function addHistory(string $type, int $entityId, string $slug): void
    {
        if (RouteSlug::where('entity_type', $type)->where('slug', $slug)->exists()) {
            return;
        }

        RouteSlug::create([
            'entity_type' => $type,
            'entity_id'   => $entityId,
            'slug'        => $slug,
            'is_current'  => false,
            'is_manual'   => false,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
    }

    public function releaseFromOthers(string $type, string $slug, int $entityId): void
    {
        RouteSlug::where('entity_type', $type)
            ->where('slug', $slug)
            ->where('entity_id', '!=', $entityId)
            ->delete();
    }

    public function deleteForEntity(string $type, int $entityId): void
    {
        RouteSlug::where('entity_type', $type)->where('entity_id', $entityId)->delete();
    }

    public function employeeCodes(): array
    {
        return EloquentUser::query()
            ->pluck('employee_code', 'id')
            ->map(fn($code) => $code === null || $code === '' ? null : (string) $code)
            ->all();
    }

    /** @return array{entity_id: int, slug: string, is_current: bool, is_manual: bool} */
    private function toArray(RouteSlug $row): array
    {
        return [
            'entity_id'  => (int) $row->entity_id,
            'slug'       => (string) $row->slug,
            'is_current' => (bool) $row->is_current,
            'is_manual'  => (bool) $row->is_manual,
        ];
    }
}
