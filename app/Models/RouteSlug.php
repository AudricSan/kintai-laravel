<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\User as EloquentUser;
use Illuminate\Database\Eloquent\Model;

final class RouteSlug extends Model
{
    protected $table = 'route_slugs';
    protected $guarded = [];
    public $timestamps = false;

    protected $casts = [
        'entity_id'  => 'integer',
        'is_current' => 'boolean',
        'is_manual'  => 'boolean',
    ];

    public const TYPE_STORE    = 'store';
    public const TYPE_EMPLOYEE = 'employee';

    public static function currentSlugs(string $type): array
    {
        return static::where('entity_type', $type)
            ->where('is_current', true)
            ->pluck('slug', 'entity_id')
            ->map(fn($slug) => (string) $slug)
            ->all();
    }

    public static function current(string $type, int $entityId): ?array
    {
        $row = static::where('entity_type', $type)
            ->where('entity_id', $entityId)
            ->where('is_current', true)
            ->first();

        return $row ? static::toArrayRow($row) : null;
    }

    public static function findBySlug(string $type, string $slug): ?array
    {
        $row = static::where('entity_type', $type)->where('slug', $slug)->first();

        return $row ? static::toArrayRow($row) : null;
    }

    public static function isTakenByOther(string $type, string $slug, int $exceptEntityId): bool
    {
        return static::where('entity_type', $type)
            ->where('slug', $slug)
            ->where('entity_id', '!=', $exceptEntityId)
            ->exists();
    }

    public static function setCurrent(string $type, int $entityId, string $slug, bool $manual): void
    {
        static::releaseFromOthers($type, $slug, $entityId);

        static::where('entity_type', $type)
            ->where('entity_id', $entityId)
            ->where('is_current', true)
            ->where('slug', '!=', $slug)
            ->update(['is_current' => false]);

        $existing = static::where('entity_type', $type)
            ->where('entity_id', $entityId)
            ->where('slug', $slug)
            ->first();

        if ($existing !== null) {
            $existing->is_current = true;
            $existing->is_manual  = $manual;
            $existing->save();
            return;
        }

        static::create([
            'entity_type' => $type,
            'entity_id'   => $entityId,
            'slug'        => $slug,
            'is_current'  => true,
            'is_manual'   => $manual,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
    }

    public static function addHistory(string $type, int $entityId, string $slug): void
    {
        if (static::where('entity_type', $type)->where('slug', $slug)->exists()) {
            return;
        }

        static::create([
            'entity_type' => $type,
            'entity_id'   => $entityId,
            'slug'        => $slug,
            'is_current'  => false,
            'is_manual'   => false,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
    }

    public static function releaseFromOthers(string $type, string $slug, int $entityId): void
    {
        static::where('entity_type', $type)
            ->where('slug', $slug)
            ->where('entity_id', '!=', $entityId)
            ->delete();
    }

    public static function deleteForEntity(string $type, int $entityId): void
    {
        static::where('entity_type', $type)->where('entity_id', $entityId)->delete();
    }

    public static function employeeCodes(): array
    {
        return EloquentUser::query()
            ->pluck('employee_code', 'id')
            ->map(fn($code) => $code === null || $code === '' ? null : (string) $code)
            ->all();
    }

    /** @return array{entity_id: int, slug: string, is_current: bool, is_manual: bool} */
    private static function toArrayRow(RouteSlug $row): array
    {
        return [
            'entity_id'  => (int) $row->entity_id,
            'slug'       => (string) $row->slug,
            'is_current' => (bool) $row->is_current,
            'is_manual'  => (bool) $row->is_manual,
        ];
    }
}
