<?php

namespace Kintai\Bundles\PlanningCheck\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Réglages de la validation du planning d'un magasin (une ligne par magasin, créée à la première sauvegarde ; sans ligne,
 * la validation est désactivée et les valeurs par défaut s'appliquent, voir `forStore()`).
 *
 * @property int $store_id
 * @property bool $enabled
 * @property string $deadline_time
 * @property string $window_start_time
 * @property list<int>|null $weekdays
 * @property list<int>|null $mail_recipient_ids
 * @property bool $include_open_shifts
 */
#[Fillable(['store_id', 'enabled', 'deadline_time', 'window_start_time', 'weekdays', 'mail_recipient_ids', 'include_open_shifts'])]
class PlanningCheckSetting extends Model
{
    public const WEEKDAYS = [1, 2, 3, 4, 5, 6, 7];

    public const MAX_RECIPIENTS = 10;

    protected $table = 'planning_check_settings';

    protected $primaryKey = 'store_id';

    public $incrementing = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'weekdays' => 'array',
            'mail_recipient_ids' => 'array',
            'include_open_shifts' => 'boolean',
        ];
    }

    public static function forStore(int $storeId): self
    {
        return self::query()->find($storeId) ?? new self([
            'store_id' => $storeId, 'enabled' => false, 'deadline_time' => '12:00', 'window_start_time' => '06:00',
            'weekdays' => self::WEEKDAYS, 'mail_recipient_ids' => [], 'include_open_shifts' => false,
        ]);
    }

    /**
     * Jours ISO concernés (tous, si rien n'est enregistré).
     *
     * @return list<int>
     */
    public function days(): array
    {
        $days = array_values(array_unique(array_filter(array_map(intval(...), $this->weekdays ?? []), fn (int $day): bool => in_array($day, self::WEEKDAYS, true))));

        return $days === [] ? self::WEEKDAYS : $days;
    }

    /**
     * @return list<int>
     */
    public function recipientIds(): array
    {
        return array_map(intval(...), $this->mail_recipient_ids ?? []);
    }
}
