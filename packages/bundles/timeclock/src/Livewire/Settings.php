<?php

namespace Kintai\Bundles\Timeclock\Livewire;

use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Kintai\Bundles\Timeclock\TimeclockAccess;
use Kintai\Contracts\Activity\ActivityRecorder;
use Kintai\Contracts\Settings\Settings as InstanceSettings;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Réglages des heures supplémentaires, réservés à l'Owner : activation, seuils journalier et hebdomadaire (en heures,
 * 0 = pas de seuil) et premier jour de la semaine. Rien n'est imposé par la réglementation d'un pays en particulier.
 */
#[Title('Overtime settings')]
class Settings extends Component
{
    use ResolvesViews;

    public bool $enabled = false;

    public int $dailyHours = 8;

    public int $weeklyHours = 40;

    public int $weekStart = 1;

    public function mount(InstanceSettings $settings): void
    {
        abort_unless(app(TimeclockAccess::class)->isOwner($this->user()), 403);

        $this->enabled = $settings->bool('timeclock.overtime_enabled');
        $this->dailyHours = $settings->int('timeclock.overtime_daily_hours');
        $this->weeklyHours = $settings->int('timeclock.overtime_weekly_hours');
        $this->weekStart = $settings->int('timeclock.overtime_week_start');
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    public function save(InstanceSettings $settings, ActivityRecorder $activity): void
    {
        abort_unless(app(TimeclockAccess::class)->isOwner($this->user()), 403);

        $this->validate([
            'enabled' => ['boolean'],
            'dailyHours' => ['required', 'integer', 'min:0', 'max:24'],
            'weeklyHours' => ['required', 'integer', 'min:0', 'max:168'],
            'weekStart' => ['required', 'integer', 'min:0', 'max:6'],
        ]);

        if ($this->enabled && $this->dailyHours === 0 && $this->weeklyHours === 0) {
            throw ValidationException::withMessages(['dailyHours' => __('Set at least one threshold, or turn overtime tracking off.')]);
        }

        $after = [
            'enabled' => $this->enabled, 'daily_hours' => $this->dailyHours, 'weekly_hours' => $this->weeklyHours, 'week_start' => $this->weekStart,
        ];
        $before = [
            'enabled' => $settings->bool('timeclock.overtime_enabled'), 'daily_hours' => $settings->int('timeclock.overtime_daily_hours'),
            'weekly_hours' => $settings->int('timeclock.overtime_weekly_hours'), 'week_start' => $settings->int('timeclock.overtime_week_start'),
        ];

        $settings->set('timeclock.overtime_enabled', $this->enabled);
        $settings->set('timeclock.overtime_daily_hours', $this->dailyHours);
        $settings->set('timeclock.overtime_weekly_hours', $this->weeklyHours);
        $settings->set('timeclock.overtime_week_start', $this->weekStart);

        $activity->record('timeclock.settings_updated', null, null, ['before' => $before, 'after' => $after], $this->user());
        Flux::toast(variant: 'success', text: __('Settings saved.'));
    }

    public function render(): View
    {
        $days = [];
        foreach ([1, 2, 3, 4, 5, 6, 0] as $dayOfWeek) {
            $days[$dayOfWeek] = Carbon::now()->startOfWeek(Carbon::SUNDAY)->addDays($dayOfWeek)->locale(app()->getLocale())->isoFormat('dddd');
        }

        return app('view')->make($this->viewName('settings'), ['days' => $days]);
    }
}
