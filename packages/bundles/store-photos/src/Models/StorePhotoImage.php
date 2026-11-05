<?php

namespace Kintai\Bundles\StorePhotos\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Kintai\Bundles\StorePhotos\PhotoStorage;

/**
 * Une photo d'un envoi. Le fichier vit sur le disque privé, sous un nom généré (`path`) ; il est supprimé avec
 * l'enregistrement.
 *
 * @property int $id
 * @property int $submission_id
 * @property string $filename
 * @property string $path
 * @property string $mime_type
 * @property int $filesize
 * @property int $width
 * @property int $height
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read StorePhotoSubmission $submission
 */
#[Fillable(['submission_id', 'filename', 'path', 'mime_type', 'filesize', 'width', 'height', 'sort_order'])]
class StorePhotoImage extends Model
{
    protected $table = 'store_photo_images';

    protected static function booted(): void
    {
        // Le fichier part avec l'enregistrement (suppression d'une photo ou d'un envoi, rétention).
        static::deleted(function (StorePhotoImage $image): void {
            app(PhotoStorage::class)->delete($image->path);
        });
    }

    /**
     * @return BelongsTo<StorePhotoSubmission, $this>
     */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(StorePhotoSubmission::class, 'submission_id');
    }
}
