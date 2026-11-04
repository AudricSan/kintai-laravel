<?php

namespace App\Models;

use Database\Factories\StoreFeatureFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Clé primaire composite (store_id, feature). Sans ligne, la fonctionnalité est active.
 *
 * @property int $store_id
 * @property string $feature
 * @property bool $enabled
 * @property-read Store $store
 */
#[Fillable(['store_id', 'feature', 'enabled'])]
class StoreFeature extends Model
{
    /** @use HasFactory<StoreFeatureFactory> */
    use HasFactory;

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
