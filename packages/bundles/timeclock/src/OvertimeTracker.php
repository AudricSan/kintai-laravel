<?php

namespace Kintai\Bundles\Timeclock;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Kintai\Bundles\Timeclock\Models\Timeclock;
use Kintai\Contracts\Notifications\Notifier;
use Kintai\Contracts\Settings\Settings;

/**
 * Heures supplémentaires des employés : lit les réglages de l'instance (`timeclock.overtime_*`), calcule la semaine d'un
 * employé (`OvertimeCalculator`, tous magasins confondus : le seuil légal vaut pour l'ensemble de ses heures) et prévient
 * une seule fois, par seuil et par période, l'employé et les gérants du magasin du pointage (`timeclock.view`).
 *
 * Seuil journalier atteint : le total du jour atteint le seuil. Seuil hebdomadaire atteint : le total de la semaine hors
 * heures supplémentaires journalières atteint le seuil.
 */
class OvertimeTracker
{
    public const EMPLOYEE_ALERT = 'timeclock_overtime';

    public const MANAGER_ALERT = 'timeclock_overtime_manager';

    /** @var array<string, OvertimeReport> */
    private array $memo = [];

    public function __construct(
        private readonly Settings $settings,
        private readonly Notifier $notifier,
        private readonly TimeclockAccess $access,
        private readonly OvertimeCalculator $calculator,
    ) {}

    public function rules(): OvertimeRules
    {
        return new OvertimeRules(
            $this->settings->bool('timeclock.overtime_enabled'),
            $this->settings->int('timeclock.overtime_daily_hours') * 60,
            $this->settings->int('timeclock.overtime_weekly_hours') * 60,
            $this->settings->int('timeclock.overtime_week_start'),
        );
    }

    /**
     * La semaine de l'employé qui contient `$at`, relue en base à chaque appel.
     */
    public function report(int $userId, CarbonInterface $at): OvertimeReport
    {
        $rules = $this->rules();
        $timezone = Timeclock::timezone();
        $start = $this->calculator->weekStart($at, $rules, $timezone);
        $from = $start->copy()->utc();
        $to = $start->copy()->addDays(7)->utc();

        $intervals = [];
        $entries = Timeclock::query()
            ->where('user_id', $userId)
            ->where('clock_in_at', '<', $to)
            ->where('clock_in_at', '>=', $from->copy()->subDays(2))
            ->where(fn ($q) => $q->whereNull('clock_out_at')->orWhere('clock_out_at', '>', $from))
            ->get(['id', 'clock_in_at', 'clock_out_at']);

        foreach ($entries as $entry) {
            $intervals[$entry->id] = [$entry->clock_in_at, $entry->clock_out_at];
        }

        return $this->calculator->week($intervals, $start, $rules, Date::now(), $timezone);
    }

    /**
     * Comme `report()`, mais mémorisé pour la durée de cette instance (listes et exports qui relisent la même semaine).
     */
    public function cachedReport(int $userId, CarbonInterface $at): OvertimeReport
    {
        $start = $this->calculator->weekStart($at, $this->rules(), Timeclock::timezone())->toDateString();

        return $this->memo["{$userId}|{$start}"] ??= $this->report($userId, $at);
    }

    /**
     * Heures supplémentaires portées par ce pointage (0 si le suivi est inactif).
     */
    public function forEntry(Timeclock $entry): int
    {
        if (! $this->rules()->active()) {
            return 0;
        }

        return $this->cachedReport($entry->user_id, $entry->clock_in_at)->forEntry($entry->id);
    }

    /**
     * Après une sortie ou une correction : signale les seuils franchis qui ne l'ont pas encore été.
     */
    public function check(Timeclock $entry): void
    {
        $rules = $this->rules();

        if (! $rules->active() || $entry->clock_out_at === null) {
            return;
        }

        $reports = [];
        foreach ([$entry->clock_in_at, $entry->clock_out_at] as $at) {
            $report = $this->report($entry->user_id, $at);
            $reports[$report->weekStart] = $report;
        }

        foreach ($reports as $report) {
            if ($rules->dailyMinutes > 0) {
                foreach ($report->days as $day => $minutes) {
                    if ($minutes >= $rules->dailyMinutes) {
                        $this->alert($entry, 'daily', (string) $day, $rules->dailyMinutes, $minutes, $report->dailyOvertime[$day] ?? 0);
                    }
                }
            }

            if ($rules->weeklyMinutes > 0 && $report->netMinutes >= $rules->weeklyMinutes) {
                $this->alert($entry, 'weekly', $report->weekStart, $rules->weeklyMinutes, $report->netMinutes, $report->weeklyOvertimeMinutes);
            }
        }
    }

    private function alert(Timeclock $entry, string $kind, string $period, int $threshold, int $worked, int $overtime): void
    {
        $inserted = DB::table('timeclock_overtime_alerts')->insertOrIgnore([
            'user_id' => $entry->user_id, 'kind' => $kind, 'period_start' => $period, 'created_at' => Date::now(),
        ]);

        if ($inserted === 0) {
            return;
        }

        $employee = User::query()->find($entry->user_id);

        if ($employee === null) {
            return;
        }

        $params = [
            'threshold' => $this->hours($threshold), 'date' => $period, 'worked' => $this->duration($worked), 'overtime' => $this->duration($overtime),
        ];

        $this->notifier->notify(
            $employee->id, self::EMPLOYEE_ALERT,
            $kind === 'daily'
                ? 'You have reached the daily overtime threshold of :threshold h on :date (:worked worked, :overtime of overtime).'
                : 'You have reached the weekly overtime threshold of :threshold h for the week starting :date (:worked worked, :overtime of overtime).',
            $params, route('timeclock.index', [], false), $entry->id,
        );

        $managers = array_values(array_filter(
            $this->access->holders($entry->store_id, 'timeclock.view'),
            fn (User $holder): bool => $holder->id !== $employee->id,
        ));

        if ($managers === []) {
            return;
        }

        $this->notifier->notify(
            array_map(fn (User $holder): int => $holder->id, $managers), self::MANAGER_ALERT,
            $kind === 'daily'
                ? ':employee has reached the daily overtime threshold of :threshold h on :date (:worked worked, :overtime of overtime).'
                : ':employee has reached the weekly overtime threshold of :threshold h for the week starting :date (:worked worked, :overtime of overtime).',
            ['employee' => $employee->name] + $params, route('timeclock.manage', [], false), $entry->id,
        );
    }

    private function hours(int $minutes): string
    {
        return rtrim(rtrim(number_format($minutes / 60, 2, '.', ''), '0'), '.');
    }

    private function duration(int $minutes): string
    {
        return intdiv($minutes, 60).'h'.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT);
    }
}
