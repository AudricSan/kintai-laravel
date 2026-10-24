<?php

declare(strict_types=1);

namespace kintai\Core\Services\Calendar;

use kintai\Core\Services\StoreTimezone;
use kintai\Core\Services\TranslationService;

/**
 * Construit l'événement Google d'un shift ou d'un congé et son empreinte. L'empreinte (SHA-256 du contenu envoyé) permet
 * de ne rien renvoyer quand le shift n'a pas changé. Aucune donnée de salaire ne figure dans un événement.
 *
 * La couleur est propre au magasin (palette Google de 10 couleurs, la 11e est réservée aux congés) : stable dans le
 * temps, et le titre contient toujours le nom du magasin quand deux magasins partagent une couleur.
 */
final class CalendarEventBuilder
{
    /** Couleur Google (« Tomate ») réservée aux congés. */
    public const TIMEOFF_COLOR_ID = '11';

    public function __construct(private readonly TranslationService $translations) {}

    /**
     * @param array<string, mixed>      $shift
     * @param array<string, mixed>      $store
     * @param array<string, mixed>|null $type
     * @param array<string, mixed>      $user
     * @return array{event: array<string, mixed>, hash: string}
     */
    public function forShift(array $shift, array $store, ?array $type, array $user, ?string $publicUrl): array
    {
        return $this->withLocale($user, $store, function () use ($shift, $store, $type, $publicUrl) {
            // Un fuseau invalide (« UTC+9 ») était ignoré par Google, qui plaçait l'événement en UTC : +9 h à Tokyo.
            $tz        = StoreTimezone::resolve($store['timezone'] ?? null);
            $date      = (string) $shift['shift_date'];
            $start     = substr((string) ($shift['start_time'] ?? '00:00'), 0, 5);
            $end       = substr((string) ($shift['end_time'] ?? '00:00'), 0, 5);
            $endDate   = !empty($shift['cross_midnight']) ? date('Y-m-d', strtotime($date . ' +1 day')) : $date;
            $typeName  = (string) ($type['name'] ?? '') !== '' ? (string) $type['name'] : __('ical_shift_fallback');

            $description = [];
            if (!empty($shift['pause_minutes'])) {
                $description[] = __('ical_pause_minutes', ['minutes' => (int) $shift['pause_minutes']]);
            }
            if (trim((string) ($shift['notes'] ?? '')) !== '') {
                $description[] = trim((string) $shift['notes']);
            }
            if ($publicUrl !== null) {
                $description[] = $publicUrl . '/employee/shifts/day?date=' . $date;
            }

            $event = [
                'summary'          => $typeName . ' — ' . (string) ($store['name'] ?? ''),
                'location'         => self::address($store),
                'description'      => implode("\n", $description),
                'start'            => ['dateTime' => $date . 'T' . $start . ':00', 'timeZone' => $tz],
                'end'              => ['dateTime' => $endDate . 'T' . $end . ':00', 'timeZone' => $tz],
                'colorId'          => (string) (1 + ((int) ($store['id'] ?? 0)) % 10),
                'transparency'     => 'opaque',
                'extendedProperties' => ['private' => ['kintai_source' => 'shift:' . (int) ($shift['id'] ?? 0)]],
            ];

            return ['event' => $event, 'hash' => self::hash($event)];
        });
    }

    /**
     * @param array<string, mixed> $absence ligne du fournisseur d'absences (start_date, end_date, type, store_id…)
     * @param array<string, mixed> $store
     * @param array<string, mixed> $user
     * @return array{event: array<string, mixed>, hash: string}
     */
    public function forTimeoff(array $absence, array $store, array $user): array
    {
        return $this->withLocale($user, $store, function () use ($absence, $store) {
            $start = (string) ($absence['start_date'] ?? date('Y-m-d'));
            $end   = (string) ($absence['end_date'] ?? $start);
            $event = [
                'summary'      => __('ical_timeoff_summary', ['store' => (string) ($store['name'] ?? '')]),
                // Événement « journée entière » : Google attend une date de fin exclusive.
                'start'        => ['date' => $start],
                'end'          => ['date' => date('Y-m-d', strtotime($end . ' +1 day'))],
                'colorId'      => self::TIMEOFF_COLOR_ID,
                'transparency' => 'transparent',
                'extendedProperties' => ['private' => ['kintai_source' => 'timeoff:' . (int) ($absence['id'] ?? 0)]],
            ];
            return ['event' => $event, 'hash' => self::hash($event)];
        });
    }

    /** @param array<string, mixed> $event */
    public static function hash(array $event): string
    {
        return hash('sha256', json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }

    /** @param array<string, mixed> $store */
    private static function address(array $store): string
    {
        $parts = array_filter(array_map(
            fn(string $k) => trim((string) ($store[$k] ?? '')),
            ['address_street', 'address_postal', 'address_city', 'address_country'],
        ), fn(string $v) => $v !== '');
        return $parts === [] ? (string) ($store['name'] ?? '') : implode(', ', $parts);
    }

    /**
     * Textes dans la langue de l'employé (profil, sinon magasin, sinon instance), comme le flux iCal.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $store
     */
    private function withLocale(array $user, array $store, \Closure $build): array
    {
        if ((string) ($user['language'] ?? '') === '' && (string) ($store['locale'] ?? '') !== '') {
            $locale = strtolower(substr((string) $store['locale'], 0, 2));
        } else {
            $locale = user_locale($user);
        }
        $previous = $this->translations->getLocale();
        $this->translations->setLocale($locale);
        try {
            return $build();
        } finally {
            $this->translations->setLocale($previous);
        }
    }
}
