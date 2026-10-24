<?php

declare(strict_types=1);

namespace kintai\Core\Services;

final class IcalService
{
    public function __construct(
        private readonly TranslationService $translations,
    ) {}

    /**
     * Génère le contenu VCALENDAR (RFC 5545) pour un employé dans un store.
     *
     * Le contenu est produit dans la langue de l'employé propriétaire du flux : ordre de
     * résolution override implicite absent ici → langue de l'utilisateur ($user['language'])
     * → locale du store ($store['locale']) → 'fr' par défaut (même pattern que
     * l'e-mail des rapports journaliers du bundle daily-report).
     *
     * @param array $shifts   Shifts de l'employé dans ce store (déjà filtrés par store_id)
     * @param array $timeoffs Congés approuvés de l'employé dans ce store (déjà filtrés)
     * @param array $store    Ligne du store (id, name, timezone, locale)
     * @param array $user     Ligne de l'utilisateur (id, first_name, last_name, language)
     * @param array $types    Map id => shift_type (pour le SUMMARY)
     */
    public function build(array $shifts, array $timeoffs, array $store, array $user, array $types = []): string
    {
        $resolved = $this->resolveLocale($user, $store);
        $previous = $this->translations->getLocale();
        $this->translations->setLocale($resolved);

        try {
            // Toujours un identifiant valide : « UTC+9 » saisi à la main faisait retomber les heures en UTC (+9 h dans l'agenda).
            $tz        = StoreTimezone::resolve($store['timezone'] ?? null);
            $storeName = $store['name'] ?? __('store');
            $calName   = $storeName . ' — ' . ($user['last_name'] ?? '') . ' ' . ($user['first_name'] ?? '');
            $dtstamp   = gmdate('Ymd\THis\Z');

            $lines = [
                'BEGIN:VCALENDAR',
                'VERSION:2.0',
                'PRODID:-//Kintai//Kintai//EN',
                'CALSCALE:GREGORIAN',
                'METHOD:PUBLISH',
                'X-WR-CALNAME:' . $this->escapeText($calName),
                'X-WR-TIMEZONE:' . $tz,
                'REFRESH-INTERVAL;VALUE=DURATION:PT1H',
                'X-PUBLISHED-TTL:PT1H',
            ];

            $cutoff = date('Y-m-d H:i:s', strtotime('-30 days'));

            foreach ($shifts as $shift) {
                if (!empty($shift['deleted_at'])) {
                    // Inclure les shifts supprimés récemment comme CANCELLED (30 jours)
                    if (($shift['deleted_at'] ?? '') >= $cutoff) {
                        $lines[] = $this->buildShiftEvent($shift, $store, $types, $dtstamp, $tz, cancelled: true);
                    }
                    continue;
                }
                $lines[] = $this->buildShiftEvent($shift, $store, $types, $dtstamp, $tz);
            }

            foreach ($timeoffs as $timeoff) {
                $lines[] = $this->buildTimeoffEvent($timeoff, $store, $dtstamp);
            }

            $lines[] = 'END:VCALENDAR';

            return implode("\r\n", $lines) . "\r\n";
        } finally {
            $this->translations->setLocale($previous);
        }
    }

    private function resolveLocale(array $user, array $store): string
    {
        // Langue du profil de l'employé, sinon celle du magasin, sinon celle de l'instance.
        if ((string) ($user['language'] ?? '') === '' && (string) ($store['locale'] ?? '') !== '') {
            return strtolower(substr((string) $store['locale'], 0, 2));
        }
        return user_locale($user);
    }

    private function buildShiftEvent(
        array $shift, array $store, array $types, string $dtstamp, string $tz,
        bool $cancelled = false
    ): string {
        $typeId   = (int) ($shift['shift_type_id'] ?? 0);
        $typeName = $types[$typeId]['name'] ?? __('ical_shift_fallback');
        $summary  = $typeName . ' — ' . ($store['name'] ?? '');

        $shiftDate = $shift['shift_date'] ?? date('Y-m-d');
        $startTime = substr($shift['start_time'] ?? '00:00', 0, 5);
        $endTime   = substr($shift['end_time'] ?? '00:00', 0, 5);

        $endDateStr = !empty($shift['cross_midnight'])
            ? date('Y-m-d', strtotime($shiftDate . ' +1 day'))
            : $shiftDate;

        $uid      = 'shift-' . ($shift['id'] ?? uniqid()) . '@kintai';
        $sequence = (int) ($shift['ical_sequence'] ?? 0);
        $created  = $this->toIcalUtc($shift['created_at'] ?? null) ?? $dtstamp;
        $modified = $this->toIcalUtc($shift['updated_at'] ?? $shift['created_at'] ?? null) ?? $dtstamp;

        $description = '';
        if (!empty($shift['pause_minutes'])) {
            $description = __('ical_pause_minutes', ['minutes' => (int) $shift['pause_minutes']]);
        }
        if (!empty($shift['notes'])) {
            $description .= ($description ? '\n' : '') . $this->escapeText($shift['notes']);
        }

        $eventLines = [
            'BEGIN:VEVENT',
            'UID:' . $uid,
            'DTSTAMP:' . $dtstamp,
            'SEQUENCE:' . $sequence,
            'CREATED:' . $created,
            'LAST-MODIFIED:' . $modified,
            'DTSTART:' . $this->localToIcalUtc($shiftDate, $startTime, $tz),
            'DTEND:' . $this->localToIcalUtc($endDateStr, $endTime, $tz),
            'SUMMARY:' . $this->escapeText($summary),
            'LOCATION:' . $this->escapeText($store['name'] ?? ''),
            'STATUS:' . ($cancelled ? 'CANCELLED' : 'CONFIRMED'),
        ];

        if ($description !== '' && !$cancelled) {
            $eventLines[] = 'DESCRIPTION:' . $description;
        }

        $eventLines[] = 'END:VEVENT';

        return implode("\r\n", array_map(fn($l) => $this->foldLine($l), $eventLines));
    }

    private function buildTimeoffEvent(array $timeoff, array $store, string $dtstamp): string
    {
        $startDate = str_replace('-', '', $timeoff['start_date'] ?? date('Y-m-d'));
        $endDate   = date('Ymd', strtotime(($timeoff['end_date'] ?? date('Y-m-d')) . ' +1 day'));

        $uid     = 'timeoff-' . ($timeoff['id'] ?? uniqid()) . '@kintai';
        $summary = __('ical_timeoff_summary', ['store' => $store['name'] ?? '']);

        $eventLines = [
            'BEGIN:VEVENT',
            'UID:' . $uid,
            'DTSTAMP:' . $dtstamp,
            'DTSTART;VALUE=DATE:' . $startDate,
            'DTEND;VALUE=DATE:' . $endDate,
            'SUMMARY:' . $this->escapeText($summary),
            'STATUS:CONFIRMED',
            'TRANSP:TRANSPARENT',
            'END:VEVENT',
        ];

        return implode("\r\n", array_map(fn($l) => $this->foldLine($l), $eventLines));
    }

    /**
     * Convertit une date+heure locale (dans le fuseau du store) en UTC au format iCal
     * `YYYYMMDDTHHmmssZ`. On exporte directement en UTC plutôt qu'en `DTSTART;TZID=...`
     * pour éviter d'avoir à fournir un bloc VTIMEZONE (RFC 5545 §3.6.5) — la plupart des
     * clients le tolèrent en résolvant le TZID IANA eux-mêmes, mais ce n'est pas garanti
     * partout ; l'UTC est non ambigu pour tout le monde.
     */
    private function localToIcalUtc(string $date, string $time, string $tz): string
    {
        try {
            $dt = new \DateTime($date . ' ' . $time, new \DateTimeZone($tz));
        } catch (\Exception) {
            $dt = new \DateTime($date . ' ' . $time, new \DateTimeZone('UTC'));
        }
        $dt->setTimezone(new \DateTimeZone('UTC'));
        return $dt->format('Ymd\THis\Z');
    }

    /** Convertit un datetime `Y-m-d H:i:s` (UTC) en format iCal UTC `YYYYMMDDTHHmmssZ`. */
    private function toIcalUtc(?string $datetime): ?string
    {
        if ($datetime === null || $datetime === '') {
            return null;
        }
        $ts = strtotime($datetime);
        return $ts !== false ? gmdate('Ymd\THis\Z', $ts) : null;
    }

    private function escapeText(string $value): string
    {
        $value = str_replace('\\', '\\\\', $value);
        $value = str_replace(';', '\;', $value);
        $value = str_replace(',', '\,', $value);
        $value = str_replace("\n", '\n', $value);
        $value = str_replace("\r", '', $value);
        return $value;
    }

    /**
     * Replie les lignes dépassant 75 octets (RFC 5545 §3.1) en préservant
     * l'intégrité des séquences UTF-8 multibyte.
     */
    private function foldLine(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $folded = '';
        while (strlen($line) > 75) {
            $chunk = substr($line, 0, 75);
            // Reculer pour ne pas couper un octet de continuation UTF-8 (10xxxxxx)
            while (strlen($chunk) > 0 && (ord($chunk[-1]) & 0xC0) === 0x80) {
                $chunk = substr($chunk, 0, -1);
            }
            $folded .= $chunk . "\r\n ";
            $line    = substr($line, strlen($chunk));
        }
        return $folded . $line;
    }
}
