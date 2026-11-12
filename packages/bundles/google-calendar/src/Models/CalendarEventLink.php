<?php

namespace Kintai\Bundles\GoogleCalendar\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Quel événement Google correspond à quelle source (`shift` : identifiant du shift ; `timeoff` : identifiant stable du
 * congé), avec l'empreinte du dernier contenu envoyé.
 *
 * @property int $id
 * @property int $connection_id
 * @property string $source_type
 * @property string $source_key
 * @property string $external_event_id
 * @property string $content_hash
 * @property Carbon|null $synced_at
 * @property-read CalendarConnection $connection
 */
#[Fillable(['connection_id', 'source_type', 'source_key', 'external_event_id', 'content_hash', 'synced_at'])]
class CalendarEventLink extends Model
{
    public const SHIFT = 'shift';

    public const TIMEOFF = 'timeoff';

    public $timestamps = false;

    protected $table = 'google_calendar_event_links';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['synced_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<CalendarConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(CalendarConnection::class, 'connection_id');
    }
}
