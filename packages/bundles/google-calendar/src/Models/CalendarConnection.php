<?php

namespace Kintai\Bundles\GoogleCalendar\Models;

use App\Models\Store;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Kintai\Bundles\GoogleCalendar\Database\Factories\CalendarConnectionFactory;

/**
 * La liaison d'un utilisateur à son compte Google. Le jeton de rafraîchissement est chiffré (cast `encrypted`) et
 * masqué : il ne sort jamais du serveur, ni en JSON, ni dans le journal.
 *
 * @property int $id
 * @property int $user_id
 * @property string|null $account_email
 * @property string $refresh_token
 * @property string|null $calendar_id
 * @property string|null $calendar_name
 * @property string $status
 * @property string $name_mode
 * @property int|null $store_id
 * @property Carbon|null $last_sync_at
 * @property string|null $last_error
 * @property Carbon|null $reconciled_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Store|null $store
 */
#[Fillable([
    'user_id', 'account_email', 'refresh_token', 'calendar_id', 'calendar_name', 'status', 'name_mode', 'store_id',
    'last_sync_at', 'last_error', 'reconciled_at',
])]
class CalendarConnection extends Model
{
    /** @use HasFactory<CalendarConnectionFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_REVOKED = 'revoked';

    public const NAME_INSTANCE = 'instance';

    public const NAME_STORE = 'store';

    protected $table = 'google_calendar_connections';

    /** @var list<string> */
    protected $hidden = ['refresh_token'];

    protected static function newFactory(): CalendarConnectionFactory
    {
        return CalendarConnectionFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'refresh_token' => 'encrypted',
            'last_sync_at' => 'datetime',
            'reconciled_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<CalendarConnection>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Le jeton de rafraîchissement en clair.
     *
     * @throws DecryptException Chiffré avec une autre clé d'application (base restaurée ailleurs).
     */
    public function decryptedRefreshToken(): string
    {
        return $this->refresh_token;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * @return HasMany<CalendarEventLink, $this>
     */
    public function links(): HasMany
    {
        return $this->hasMany(CalendarEventLink::class, 'connection_id');
    }

    /**
     * @return HasMany<CalendarSyncQueueItem, $this>
     */
    public function queue(): HasMany
    {
        return $this->hasMany(CalendarSyncQueueItem::class, 'connection_id');
    }
}
