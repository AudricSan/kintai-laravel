<?php

namespace App\Ical;

use App\Contracts\Planning\TimeOffPeriod;
use App\Models\Shift;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Construit un VCALENDAR (RFC 5545) : un VEVENT par shift, en UTC, plus les congés en journée entière.
 *
 * Les shifts doivent arriver avec leurs relations `store`, `shiftType` (et `user` pour `$withEmployee`) chargées.
 */
final class IcalCalendar
{
    /** @var list<string> */
    private array $events = [];

    public function __construct(
        private readonly string $name,
        private readonly CarbonInterface $now,
    ) {}

    /**
     * @param  bool  $cancelled  Shift supprimé récemment : publié `STATUS:CANCELLED`, séquence + 1 pour primer sur la dernière version publiée.
     * @param  bool  $withEmployee  Préfixe le titre du nom de l'employé (calendrier de plusieurs personnes).
     */
    public function addShift(Shift $shift, bool $cancelled = false, bool $withEmployee = false): self
    {
        $typeName = $shift->shiftType->name ?? __('Shift');
        $summary = $typeName.' — '.$shift->store->name;
        if ($withEmployee && $shift->user !== null) {
            $summary = $shift->user->name.' — '.$summary;
        }

        $description = [];
        if ($shift->pause_minutes > 0) {
            $description[] = __('Break: :minutes min', ['minutes' => $shift->pause_minutes]);
        }
        if ($shift->notes !== null && trim($shift->notes) !== '') {
            $description[] = trim($shift->notes);
        }

        $lines = [
            'BEGIN:VEVENT',
            'UID:'.$shift->ical_uid,
            'DTSTAMP:'.self::utc($shift->updated_at ?? $this->now),
            'SEQUENCE:'.($shift->ical_sequence + ($cancelled ? 1 : 0)),
            'CREATED:'.self::utc($shift->created_at ?? $this->now),
            'LAST-MODIFIED:'.self::utc($shift->updated_at ?? $this->now),
            'DTSTART:'.self::utc($shift->starts_at),
            'DTEND:'.self::utc($shift->ends_at),
            'SUMMARY:'.IcalText::escape($summary),
            'LOCATION:'.IcalText::escape($shift->store->name),
            'STATUS:'.($cancelled ? 'CANCELLED' : 'CONFIRMED'),
        ];

        if ($description !== [] && ! $cancelled) {
            // Les sauts de ligne entre les parties sont des \n littéraux ; chaque partie est échappée séparément.
            $lines[] = 'DESCRIPTION:'.implode('\n', array_map(IcalText::escape(...), $description));
        }

        $lines[] = 'END:VEVENT';
        $this->events[] = $this->join($lines);

        return $this;
    }

    /**
     * Congé en journée entière (DTEND exclusif, donc le lendemain du dernier jour).
     */
    public function addTimeOff(TimeOffPeriod $period, string $storeName): self
    {
        $start = CarbonImmutable::parse($period->startDate);
        $end = CarbonImmutable::parse($period->endDate)->addDay();

        $this->events[] = $this->join([
            'BEGIN:VEVENT',
            'UID:'.$period->uid,
            'DTSTAMP:'.self::utc($this->now),
            'DTSTART;VALUE=DATE:'.$start->format('Ymd'),
            'DTEND;VALUE=DATE:'.$end->format('Ymd'),
            'SUMMARY:'.IcalText::escape(__('Time off — :store', ['store' => $storeName])),
            'STATUS:CONFIRMED',
            'TRANSP:TRANSPARENT',
            'END:VEVENT',
        ]);

        return $this;
    }

    public function render(): string
    {
        $head = $this->join([
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Kintai//Kintai//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:'.IcalText::escape($this->name),
            'REFRESH-INTERVAL;VALUE=DURATION:PT1H',
            'X-PUBLISHED-TTL:PT1H',
        ]);

        return implode("\r\n", [$head, ...$this->events, 'END:VCALENDAR'])."\r\n";
    }

    /**
     * @param  list<string>  $lines
     */
    private function join(array $lines): string
    {
        return implode("\r\n", array_map(IcalText::fold(...), $lines));
    }

    private static function utc(CarbonInterface $date): string
    {
        return CarbonImmutable::instance($date)->utc()->format('Ymd\THis\Z');
    }
}
