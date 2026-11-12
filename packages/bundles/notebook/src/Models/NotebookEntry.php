<?php

namespace Kintai\Bundles\Notebook\Models;

use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Note du carnet d'équipe.
 *
 * @property int $id
 * @property int $author_id
 * @property int|null $store_id Null = toute l'organisation.
 * @property string $content
 * @property bool $pinned
 * @property Carbon|null $expires_on
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $author
 * @property-read Store|null $store
 */
#[Fillable(['author_id', 'store_id', 'content', 'pinned', 'expires_on'])]
class NotebookEntry extends Model
{
    protected $table = 'notebook_entries';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['pinned' => 'boolean', 'expires_on' => 'date:Y-m-d'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function isOrganizationWide(): bool
    {
        return $this->store_id === null;
    }

    /**
     * Expirée si son dernier jour est passé (dans le fuseau de l'instance).
     */
    public function isExpired(): bool
    {
        return $this->expires_on !== null && $this->expires_on->toDateString() < self::today();
    }

    public static function today(): string
    {
        return Carbon::now((string) config('kintai.timezone', 'UTC'))->toDateString();
    }
}
