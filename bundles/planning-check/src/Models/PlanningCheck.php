<?php

declare(strict_types=1);

namespace kintai\Bundles\Installed\PlanningCheck\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Validation du planning d'un magasin pour un jour (table planning_checks, une ligne par magasin et par jour).
 */
final class PlanningCheck extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_VALIDATED = 'validated';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_ESCALATED = 'escalated';

    protected $table = 'planning_checks';
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = [
        'id'              => 'integer',
        'store_id'        => 'integer',
        'submitted_by'    => 'integer',
        'validated_by'    => 'integer',
        'rejected_by'     => 'integer',
        'editing_by'      => 'integer',
        'confirmed_empty' => 'boolean',
    ];

    /** @return array<string, mixed>|null */
    public static function find(int $id): ?array
    {
        return self::query()->whereKey($id)->first()?->toArray();
    }

    /** @return array<string, mixed>|null */
    public static function findFor(int $storeId, string $targetDate): ?array
    {
        return self::query()->where('store_id', $storeId)->where('target_date', $targetDate)->first()?->toArray();
    }

    /** La validation du magasin pour ce jour, créée (« pending ») si elle n'existe pas encore. @return array<string, mixed> */
    public static function ensure(int $storeId, string $targetDate): array
    {
        $existing = self::findFor($storeId, $targetDate);
        if ($existing !== null) {
            return $existing;
        }
        try {
            self::query()->create([
                'store_id'    => $storeId,
                'target_date' => $targetDate,
                'status'      => self::STATUS_PENDING,
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) {
            // Deux requêtes simultanées : l'autre a créé la ligne (index unique) ; on la relit.
        }

        return self::findFor($storeId, $targetDate) ?? throw new \RuntimeException('planning_check_not_created');
    }

    /** @param array<string, mixed> $changes @return array<string, mixed> la ligne mise à jour */
    public static function put(int $id, array $changes): array
    {
        self::query()->whereKey($id)->update($changes + ['updated_at' => date('Y-m-d H:i:s')]);

        return self::find($id) ?? throw new \RuntimeException('planning_check_not_found');
    }

    /** @return list<array<string, mixed>> les plus récentes d'abord */
    public static function recentForStore(int $storeId, int $limit = 30): array
    {
        return self::query()->where('store_id', $storeId)->orderByDesc('target_date')->limit($limit)->get()->map(fn(self $c): array => $c->toArray())->all();
    }

    /**
     * Validations en attente d'approbation (« submitted ») pour ces magasins, les plus anciennes d'abord.
     *
     * @param list<int> $storeIds
     * @return list<array<string, mixed>>
     */
    public static function submittedForStores(array $storeIds): array
    {
        if ($storeIds === []) {
            return [];
        }

        return self::query()->whereIn('store_id', array_map('intval', $storeIds))
            ->where('status', self::STATUS_SUBMITTED)->orderBy('submitted_at')->get()->map(fn(self $c): array => $c->toArray())->all();
    }
}
