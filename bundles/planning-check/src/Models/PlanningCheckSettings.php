<?php

declare(strict_types=1);

namespace kintai\Bundles\Installed\PlanningCheck\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Réglages de validation du planning d'un magasin (table planning_check_settings, une ligne par magasin). Les
 * identifiants d'utilisateur ne sont que des références (pas de clé étrangère) : l'historique survit à la suppression
 * d'un compte.
 */
final class PlanningCheckSettings extends Model
{
    public const DEFAULT_SETTINGS = [
        'enabled'             => false,
        'deadline_time'       => '12:00',
        'window_start_time'   => '06:00',
        'weekdays'            => [1, 2, 3, 4, 5, 6, 7],
        'mail_recipient_ids'  => [],
        'mail_recipients'     => [],
        'include_open_shifts' => false,
    ];

    protected $table = 'planning_check_settings';
    protected $primaryKey = 'store_id';
    public $incrementing = false;
    public $timestamps = false;
    protected $guarded = [];

    /**
     * Réglages du magasin, complétés par les valeurs par défaut.
     *
     * @return array{enabled: bool, deadline_time: string, window_start_time: string, weekdays: list<int>, mail_recipient_ids: list<int>, mail_recipients: list<string>, include_open_shifts: bool}
     */
    public static function forStore(int $storeId): array
    {
        $row      = self::query()->find($storeId);
        $defaults = self::DEFAULT_SETTINGS;
        if ($row === null) {
            return $defaults;
        }
        $weekdays = array_values(array_unique(array_filter(
            array_map('intval', explode(',', (string) $row->weekdays)),
            static fn(int $d): bool => $d >= 1 && $d <= 7,
        )));
        $recipients   = json_decode((string) $row->mail_recipients, true);
        $recipientIds = json_decode((string) $row->mail_recipient_ids, true);

        return [
            'enabled'             => (bool) $row->enabled,
            'deadline_time'       => self::time((string) $row->deadline_time, $defaults['deadline_time']),
            'window_start_time'   => self::time((string) $row->window_start_time, $defaults['window_start_time']),
            'weekdays'            => $weekdays !== [] ? $weekdays : $defaults['weekdays'],
            'mail_recipient_ids'  => is_array($recipientIds) ? array_values(array_map('intval', $recipientIds)) : [],
            'mail_recipients'     => is_array($recipients) ? array_values(array_filter($recipients, 'is_string')) : [],
            'include_open_shifts' => (bool) $row->include_open_shifts,
        ];
    }

    /** @param array<string, mixed> $settings déjà validés (voir PlanningCheckSettingsValidator) */
    public static function put(int $storeId, array $settings): void
    {
        self::query()->updateOrCreate(['store_id' => $storeId], [
            'enabled'             => !empty($settings['enabled']) ? 1 : 0,
            'deadline_time'       => (string) $settings['deadline_time'],
            'window_start_time'   => (string) $settings['window_start_time'],
            'weekdays'            => implode(',', array_map('intval', (array) $settings['weekdays'])),
            'mail_recipient_ids'  => json_encode(array_values(array_map('intval', (array) ($settings['mail_recipient_ids'] ?? []))), JSON_UNESCAPED_UNICODE),
            'mail_recipients'     => json_encode(array_values((array) $settings['mail_recipients']), JSON_UNESCAPED_UNICODE),
            'include_open_shifts' => !empty($settings['include_open_shifts']) ? 1 : 0,
            'updated_at'          => date('Y-m-d H:i:s'),
        ]);
    }

    /** @return list<int> magasins pour lesquels la validation est activée */
    public static function enabledStoreIds(): array
    {
        return array_values(array_map('intval', self::query()->where('enabled', 1)->pluck('store_id')->all()));
    }

    private static function time(string $value, string $default): string
    {
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1 ? $value : $default;
    }
}
