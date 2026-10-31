<?php

declare(strict_types=1);

namespace kintai\Bundles\Installed\StorePhoto\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Image d'un envoi de photos (table store_photo_images). Repris du Core à l'étape 4 du plan du contrat (extraction).
 */
final class StorePhotoImage extends Model
{
    protected $table = 'store_photo_images';
    protected $guarded = [];
    public $timestamps = false;

    public static function findBySubmission(int $submissionId): array
    {
        return self::where('submission_id', $submissionId)
            ->orderBy('sort_order')
            ->get()
            ->toArray();
    }

    public static function findById(int $id): ?array
    {
        $img = self::find($id);
        return $img ? $img->toArray() : null;
    }

    public static function put(array $data): array
    {
        if (!empty($data['id'])) {
            $img = self::findOrFail($data['id']);
            $img->fill($data);
            $img->save();
        } else {
            $img = self::create($data);
        }
        return $img->toArray();
    }

    public static function remove(int $id): int
    {
        $img = self::find($id);
        if ($img) {
            return $img->delete() ? 1 : 0;
        }
        return 0;
    }
}
