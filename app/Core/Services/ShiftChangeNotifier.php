<?php

declare(strict_types=1);

namespace kintai\Core\Services;

use kintai\Contract\Event\ShiftDeleted;
use kintai\Contract\Event\ShiftSaved;
use kintai\Core\Container;
use kintai\Core\Extension\Events;

/**
 * Point unique par lequel le dépôt des shifts annonce une écriture : événements ShiftSaved / ShiftDeleted pour les bundles
 * (contrat 1.28) et mise en file de la synchronisation des agendas externes du Core. Appelé depuis DatabaseShiftRepository,
 * donc quel que soit le chemin d'écriture. Ne lève jamais : une panne ici ne doit pas défaire l'écriture du shift.
 */
final class ShiftChangeNotifier
{
    /** @param ?callable(string, array<string, mixed>, ?array<string, mixed>): void $calendarHook remplacé en test */
    public function __construct(private readonly ?Events $events = null, private readonly mixed $calendarHook = null) {}

    private static ?self $override = null;

    public static function current(): self
    {
        return self::$override ?? new self();
    }

    /** Remplace l'instance courante (tests du dépôt) ; null rétablit le comportement normal. */
    public static function override(?self $notifier): void
    {
        self::$override = $notifier;
    }

    /** @param array<string, mixed> $shift @param array<string, mixed>|null $before */
    public function saved(array $shift, ?array $before = null): void
    {
        $this->announce(new ShiftSaved($shift, $before), 'saved', $shift, $before);
    }

    /** @param array<string, mixed> $shift */
    public function deleted(array $shift): void
    {
        $this->announce(new ShiftDeleted($shift), 'deleted', $shift, null);
    }

    /** @param array<string, mixed> $shift @param array<string, mixed>|null $before */
    private function announce(object $event, string $kind, array $shift, ?array $before): void
    {
        try {
            ($this->events ?? Events::current())->dispatch($event);
        } catch (\Throwable $e) {
            self::warn('shift_event_failed', $e);
        }

        try {
            if ($this->calendarHook !== null) {
                ($this->calendarHook)($kind, $shift, $before);
                return;
            }
            $container = Container::getInstance();
            if ($container->has(CalendarSyncService::class) || class_exists(CalendarSyncService::class)) {
                $container->make(CalendarSyncService::class)->shiftChanged($kind, $shift, $before);
            }
        } catch (\Throwable $e) {
            // Table absente (instance non migrée) ou conteneur indisponible : la synchro d'agenda est facultative.
            self::warn('shift_calendar_enqueue_failed', $e);
        }
    }

    private static function warn(string $event, \Throwable $e): void
    {
        try {
            Log::warning($event, ['error' => $e->getMessage()]);
        } catch (\Throwable) {
            // Le journal peut dépendre de la base : ne jamais aggraver la panne.
        }
    }
}
