<?php

declare(strict_types=1);


namespace kintai\Core\Repositories;

use kintai\Domain\Eloquent\Store as EloquentStore;
use kintai\Domain\Eloquent\StoreFeature;
use kintai\Domain\Eloquent\StoreImportSetting;
use kintai\Domain\Eloquent\StoreDeductionSetting;

final class DatabaseStoreRepository implements StoreRepositoryInterface
{
    public function __construct() {}

    public function findById(int $id): ?array
    {
        $store = EloquentStore::find($id);
        return $store ? $store->toArray() : null;
    }

    public function findByCode(string $code): ?array
    {
        $store = EloquentStore::where('code', $code)->whereNull('deleted_at')->first();
        return $store ? $store->toArray() : null;
    }

    public function findAll(): array
    {
        return EloquentStore::whereNull('deleted_at')->get()->toArray();
    }

    public function findAllWithDeleted(): array
    {
        return EloquentStore::all()->toArray();
    }

    public function findActive(): array
    {
        return EloquentStore::where('is_active', 1)
            ->whereNull('deleted_at')
            ->get()
            ->toArray();
    }

    public function countActive(): int
    {
        return EloquentStore::where('is_active', 1)
            ->whereNull('deleted_at')
            ->count();
    }

    public function save(array $data): array
    {
        if (!empty($data['id'])) {
            $store = EloquentStore::findOrFail($data['id']);
            $store->fill($data);
            $store->save();
        } else {
            $store = EloquentStore::create($data);
        }
        return $store->toArray();
    }

    public function delete(int $id): int
    {
        $store = EloquentStore::whereNull('deleted_at')->find($id);
        if (!$store) {
            return 0;
        }

        // Alias d'URL du magasin (courant et historique) : libérés pour un futur magasin.
        try {
            \kintai\Domain\Eloquent\RouteSlug::where('entity_type', 'store')->where('entity_id', $id)->delete();
        } catch (\Illuminate\Database\QueryException) {
            // Table route_slugs absente (migration pas encore jouée).
        }

        // Suppression douce (audit du 13/10/2026, M8) : effacer la ligne supprimait en cascade, sous MySQL,
        // les shifts, pointages et rapports du magasin, et les laissait orphelins sous SQLite. Le code,
        // unique et utilisé à la connexion par code, est libéré pour pouvoir être réattribué.
        $store->fill([
            'code'       => 'DEL-' . $id . '-' . $store->code,
            'is_active'  => 0,
            'deleted_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return $store->save() ? 1 : 0;
    }

    public function getFeatures(int $storeId): array
    {
        $decisions = $this->getFeatureDecisions($storeId);
        $disabled  = array_keys(array_filter($decisions, static fn(bool $on): bool => !$on));
        if ($disabled === []) {
            return [];
        }
        // Actives : celles réglées « active », plus les fonctionnalités proposées jamais réglées.
        $enabled = array_keys(array_filter($decisions));
        $available = \kintai\Core\Extension\StoreFeatures::current()->availableKeys();
        return array_values(array_unique(array_merge($enabled, array_diff($available, $disabled))));
    }

    public function saveFeatures(int $storeId, array $features): void
    {
        StoreFeature::where('store_id', $storeId)->delete();
        $decisions = array_fill_keys(\kintai\Core\Extension\StoreFeatures::current()->availableKeys(), false);
        foreach ($features as $key) {
            $decisions[(string) $key] = true;
        }
        $this->saveFeatureDecisions($storeId, $decisions);
    }

    public function getFeatureDecisions(int $storeId): array
    {
        $decisions = [];
        foreach (StoreFeature::where('store_id', $storeId)->get(['feature_key', 'enabled']) as $row) {
            $decisions[(string) $row->feature_key] = (bool) $row->enabled;
        }
        return $decisions;
    }

    public function saveFeatureDecisions(int $storeId, array $decisions): void
    {
        foreach ($decisions as $key => $enabled) {
            $existing = StoreFeature::where('store_id', $storeId)->where('feature_key', (string) $key);
            if ($existing->exists()) {
                $existing->update(['enabled' => (bool) $enabled]);
            } else {
                StoreFeature::create(['store_id' => $storeId, 'feature_key' => (string) $key, 'enabled' => (bool) $enabled]);
            }
        }
    }

    public function getImportSettings(int $storeId): array
    {
        $row = StoreImportSetting::where('store_id', $storeId)->first();
        return $row ? $row->toArray() : [];
    }

    public function saveImportSettings(int $storeId, array $settings): void
    {
        StoreImportSetting::updateOrCreate(
            ['store_id' => $storeId],
            $settings
        );
    }

    public function getDeductionSettings(int $storeId): array
    {
        $row = StoreDeductionSetting::where('store_id', $storeId)->first();
        return $row ? $row->toArray() : [];
    }

    public function saveDeductionSettings(int $storeId, array $settings): void
    {
        StoreDeductionSetting::updateOrCreate(
            ['store_id' => $storeId],
            $settings
        );
    }
}
