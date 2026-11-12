<?php

namespace Kintai\Bundles\StorePhotos\Models;

use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Un envoi de photos d'un magasin (les envois d'un même jour s'ajoutent à un seul rapport).
 *
 * @property int $id
 * @property int $store_id
 * @property int|null $created_by
 * @property string|null $notes
 * @property Carbon|null $archived_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Store $store
 * @property-read User|null $author
 */
#[Fillable(['store_id', 'created_by', 'notes', 'archived_at'])]
class StorePhotoSubmission extends Model
{
    protected $table = 'store_photo_submissions';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['archived_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    /**
     * @return HasMany<StorePhotoImage, $this>
     */
    public function images(): HasMany
    {
        return $this->hasMany(StorePhotoImage::class, 'submission_id')->orderBy('sort_order')->orderBy('id');
    }
}
