<?php

namespace Kintai\Bundles\GoogleCalendar;

use App\Models\Shift;
use App\Models\Store;
use App\Models\User;
use App\Support\Locales;
use Closure;
use Kintai\Contracts\Planning\TimeOffPeriod;

/**
 * Construit l'événement Google d'un shift ou d'un congé et son empreinte. L'empreinte (SHA-256 du contenu envoyé) permet
 * de ne rien renvoyer quand la source n'a pas changé. Aucune donnée de salaire ne figure dans un événement.
 *
 * La couleur est propre au magasin (palette Google de 10 couleurs, la 11e est réservée aux congés) : stable dans le
 * temps, et le titre contient toujours le nom du magasin quand deux magasins partagent une couleur.
 */
class CalendarEventBuilder
{
    /** Couleur Google (« Tomate ») réservée aux congés. */
    public const TIMEOFF_COLOR_ID = '11';

    public function __construct(private readonly GoogleRedirectUri $redirect) {}

    /**
     * @return array{event: array<string, mixed>, hash: string}
     */
    public function forShift(Shift $shift, User $user): array
    {
        $store = $shift->store;

        return $this->withLocale($user, $store, function () use ($shift, $store): array {
            // Fuseau de l'instance (comme les heures locales du shift), pas celui du magasin.
            $timezone = (string) config('kintai.timezone', 'UTC');
            $typeName = $shift->shiftType->name ?? __('Shift');

            $description = [];

            if ($shift->pause_minutes > 0) {
                $description[] = __('Break: :minutes min', ['minutes' => $shift->pause_minutes]);
            }

            if ($shift->notes !== null && trim($shift->notes) !== '') {
                $description[] = trim($shift->notes);
            }

            $description[] = $this->redirect->root().route('my-shifts.day', ['date' => $shift->shift_date->toDateString()], false);

            $event = [
                'summary' => $typeName.' — '.$store->name,
                'location' => self::address($store),
                'description' => implode("\n", $description),
                'start' => ['dateTime' => $shift->localStartsAt->format('Y-m-d\TH:i:s'), 'timeZone' => $timezone],
                'end' => ['dateTime' => $shift->localEndsAt->format('Y-m-d\TH:i:s'), 'timeZone' => $timezone],
                'colorId' => (string) (1 + $store->id % 10),
                'transparency' => 'opaque',
                'extendedProperties' => ['private' => ['kintai_source' => 'shift:'.$shift->id]],
            ];

            return ['event' => $event, 'hash' => self::hash($event)];
        });
    }

    /**
     * @return array{event: array<string, mixed>, hash: string}
     */
    public function forTimeOff(TimeOffPeriod $period, Store $store, User $user): array
    {
        return $this->withLocale($user, $store, function () use ($period, $store): array {
            $event = [
                'summary' => __('Time off — :store', ['store' => $store->name]),
                // Événement « journée entière » : Google attend une date de fin exclusive.
                'start' => ['date' => $period->startDate],
                'end' => ['date' => date('Y-m-d', (int) strtotime($period->endDate.' +1 day'))],
                'colorId' => self::TIMEOFF_COLOR_ID,
                'transparency' => 'transparent',
                'extendedProperties' => ['private' => ['kintai_source' => 'timeoff:'.$period->uid]],
            ];

            return ['event' => $event, 'hash' => self::hash($event)];
        });
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public static function hash(array $event): string
    {
        return hash('sha256', json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }

    private static function address(Store $store): string
    {
        $parts = array_filter(
            array_map(fn (?string $part): string => trim((string) $part), [$store->address_street, $store->address_postal, $store->address_city, $store->address_country]),
            fn (string $part): bool => $part !== '',
        );

        return $parts === [] ? $store->name : implode(', ', $parts);
    }

    /**
     * Textes dans la langue de l'employé (profil), sinon celle du magasin, sinon celle de l'instance, comme le flux iCal.
     *
     * @template T
     *
     * @param  Closure(): T  $build
     * @return T
     */
    private function withLocale(User $user, Store $store, Closure $build): mixed
    {
        $locale = match (true) {
            Locales::isSupported($user->locale) => (string) $user->locale,
            Locales::isSupported($store->locale) => (string) $store->locale,
            default => $user->preferredLocale(),
        };

        $previous = app()->getLocale();
        app()->setLocale($locale);

        try {
            return $build();
        } finally {
            app()->setLocale($previous);
        }
    }
}
