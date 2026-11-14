<?php

namespace Kintai\Bundles\DailyReport\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Réglages des rapports d'un magasin (une ligne par magasin, créée à la première sauvegarde ; sans ligne, les valeurs par
 * défaut s'appliquent, voir `DEFAULTS`).
 *
 * @property int $store_id
 * @property bool $auto_send_on_validate
 * @property string|null $auto_validate_time
 * @property string $reminder_time
 * @property string $no_report_time
 * @property list<string>|null $show_columns
 * @property string $cumulative_mode
 * @property list<int>|null $mail_recipient_ids
 */
#[Fillable(['store_id', 'auto_send_on_validate', 'auto_validate_time', 'reminder_time', 'no_report_time', 'show_columns', 'cumulative_mode', 'mail_recipient_ids'])]
class DailyReportSetting extends Model
{
    public const COLUMNS = ['employee', 'type', 'schedule', 'gross_hours', 'pause', 'net_hours'];

    public const MODES = ['per_day', 'cumulative_system', 'cumulative_input'];

    public const MAX_RECIPIENTS = 20;

    /**
     * Valeurs appliquées (sans être enregistrées) quand un magasin n'a pas de ligne : `firstOrNew(['store_id' => $id], self::DEFAULTS)`.
     */
    public const DEFAULTS = [
        'auto_send_on_validate' => false, 'auto_validate_time' => null, 'reminder_time' => '15:00', 'no_report_time' => '18:00',
        'show_columns' => self::COLUMNS, 'cumulative_mode' => 'per_day', 'mail_recipient_ids' => [],
    ];

    protected $table = 'daily_report_settings';

    protected $primaryKey = 'store_id';

    public $incrementing = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'auto_send_on_validate' => 'boolean',
            'show_columns' => 'array',
            'mail_recipient_ids' => 'array',
        ];
    }

    /**
     * @return list<string>
     */
    public function columns(): array
    {
        $columns = array_values(array_intersect($this->show_columns ?? [], self::COLUMNS));

        return $columns === [] ? self::COLUMNS : $columns;
    }

    /**
     * @return list<int>
     */
    public function recipientIds(): array
    {
        return array_map(intval(...), $this->mail_recipient_ids ?? []);
    }
}
