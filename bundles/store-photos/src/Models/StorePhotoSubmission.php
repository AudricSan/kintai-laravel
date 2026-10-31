<?php

declare(strict_types=1);

namespace kintai\Bundles\Installed\StorePhoto\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Envoi de photos d'un magasin (table store_photo_submissions). Repris du Core à l'étape 4 du plan du contrat
 * (extraction) : la table garde son nom et ses données.
 */
final class StorePhotoSubmission extends Model
{
    protected $table = 'store_photo_submissions';
    protected $guarded = [];
    public $timestamps = false;

    public static function findById(int $id): ?array
    {
        $s = self::find($id);
        return $s ? $s->toArray() : null;
    }

    public static function findByStore(int $storeId): array
    {
        return self::where('store_id', $storeId)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->get()
            ->toArray();
    }

    public static function findAll(?array $storeIds = null, int $limit = 50): array
    {
        $q = self::whereNull('deleted_at');
        if ($storeIds !== null) {
            $q->whereIn('store_id', $storeIds);
        }
        return $q->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    public static function findToday(int $storeId, string $date): ?array
    {
        $start = $date . ' 00:00:00';
        $end   = date('Y-m-d 00:00:00', strtotime($date . ' +1 day'));

        $s = self::where('store_id', $storeId)
            ->whereNull('deleted_at')
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->orderByDesc('created_at')
            ->first();

        return $s ? $s->toArray() : null;
    }

    public static function put(array $data): array
    {
        if (!empty($data['id'])) {
            $s = self::findOrFail($data['id']);
            $s->fill($data);
            $s->save();
        } else {
            $s = self::create($data);
        }
        return $s->toArray();
    }

    public static function remove(int $id): int
    {
        $s = self::find($id);
        if ($s) {
            StorePhotoImage::where('submission_id', $id)->delete();
            return $s->delete() ? 1 : 0;
        }
        return 0;
    }

    public static function findExpired(int $retentionDays): array
    {
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$retentionDays} days"));
        return self::whereNull('deleted_at')
            ->where('created_at', '<', $cutoff)
            ->orderBy('created_at')
            ->get()
            ->toArray();
    }

    public static function findPendingCompression(int $retentionDays): array
    {
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$retentionDays} days"));
        return self::whereNull('deleted_at')
            ->where('compressed', 0)
            ->where('created_at', '<', $cutoff)
            ->orderBy('created_at')
            ->get()
            ->toArray();
    }
}
