<?php

namespace App\Ical;

use App\Contracts\Planning\TimeOffProvider;
use App\Models\Shift;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Contenu du flux iCal d'un employé pour un magasin : ses shifts de la période, les shifts supprimés depuis moins de
 * 30 jours (publiés comme annulés, y compris un shift remplacé) et ses congés approuvés.
 */
class IcalFeedBuilder
{
    /** Jours avant aujourd'hui couverts par le flux. */
    public const DAYS_BEFORE = 60;

    /** Jours après aujourd'hui couverts par le flux. */
    public const DAYS_AFTER = 365;

    /** Durée pendant laquelle un shift supprimé reste publié comme annulé. */
    public const CANCELLED_DAYS = 30;

    public function __construct(private readonly TimeOffProvider $timeOff) {}

    public function build(User $user, Store $store, ?CarbonInterface $now = null): string
    {
        $now = CarbonImmutable::instance($now ?? CarbonImmutable::now());
        $today = $now->setTimezone((string) config('kintai.timezone'))->startOfDay();
        $from = $today->subDays(self::DAYS_BEFORE);
        $to = $today->addDays(self::DAYS_AFTER);

        // Le flux est produit dans la langue de l'employé qui le lit.
        $previous = app()->getLocale();
        app()->setLocale($user->preferredLocale());

        try {
            $calendar = new IcalCalendar($store->name.' — '.$user->name, $now);

            $shifts = Shift::query()
                ->withTrashed()
                ->with(['store', 'shiftType'])
                ->where('user_id', $user->id)
                ->where('store_id', $store->id)
                ->whereBetween('shift_date', [$from->toDateString(), $to->toDateString()])
                ->where(function (Builder $query) use ($now): void {
                    $query->whereNull('deleted_at')
                        ->orWhere('deleted_at', '>=', $now->subDays(self::CANCELLED_DAYS));
                })
                ->orderBy('starts_at')
                ->orderBy('id')
                ->get();

            foreach ($shifts as $shift) {
                $calendar->addShift($shift, cancelled: $shift->trashed());
            }

            foreach ($this->timeOff->approvedFor($user, $store, $from, $to) as $period) {
                $calendar->addTimeOff($period, $store->name);
            }

            return $calendar->render();
        } finally {
            app()->setLocale($previous);
        }
    }
}
