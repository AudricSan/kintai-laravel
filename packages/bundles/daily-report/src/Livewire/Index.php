<?php

namespace Kintai\Bundles\DailyReport\Livewire;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Kintai\Bundles\DailyReport\DailyReportAccess;
use Kintai\Bundles\DailyReport\Models\DailyReport;
use Kintai\Bundles\DailyReport\Models\DailyReportSetting;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Liste des rapports : ceux des magasins où l'on a `daily_reports.view`, les siens ailleurs. Avec un seul magasin
 * choisi, les jours sans rapport apparaissent (« Aucun rapport », après l'heure réglée), le cumul est calculé si le
 * magasin saisit au jour le jour, et un rappel s'affiche entre l'heure de rappel et l'heure « sans rapport ».
 */
#[Title('Daily reports')]
class Index extends Component
{
    use ResolvesViews;

    private const LIMIT = 500;

    #[Url(except: '')]
    public string $store = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    /** « day » : une liste à plat par jour ; « store » : un bloc par magasin. */
    #[Url(except: 'day')]
    public string $view = 'day';

    public function mount(): void
    {
        $stores = $this->access()->listableStores($this->user());
        abort_if($stores === [], 404);

        if ($this->from === '') {
            $this->from = Carbon::now((string) config('kintai.timezone', 'UTC'))->startOfMonth()->toDateString();
        }

        if (count($stores) === 1) {
            $this->store = (string) array_key_first($stores);
        }
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    private function access(): DailyReportAccess
    {
        return app(DailyReportAccess::class);
    }

    public function render(): View
    {
        $user = $this->user();
        $access = $this->access();
        $stores = $access->listableStores($user);
        $single = ctype_digit($this->store) && isset($stores[(int) $this->store]) ? (int) $this->store : null;

        $reports = $access->visible($user)
            ->with(['store:id,name,currency,currency_symbol_style', 'author:id,name'])
            ->when($single !== null, fn ($q) => $q->where('store_id', $single))
            ->when(in_array($this->status, DailyReport::STATUSES, true), fn ($q) => $q->where('status', $this->status))
            ->when($this->validDate($this->from), fn ($q) => $q->whereDate('report_date', '>=', $this->from))
            ->when($this->validDate($this->to), fn ($q) => $q->whereDate('report_date', '<=', $this->to))
            ->orderByDesc('report_date')
            ->orderBy('store_id')
            ->limit(self::LIMIT)
            ->get();

        // Réglages, jours sans rapport, cumul et rappel : propres à un magasin choisi.
        $setting = null;
        $rows = null;
        $reminder = false;
        $cumulative = [];
        if ($single !== null) {
            $setting = DailyReportSetting::forStore($single);
            $rows = $this->rowsWithGaps($reports, $setting);
            $reminder = $access->canCreate($user, $single) && $this->reminderDue($setting);
            $cumulative = $setting->cumulative_mode === 'cumulative_system' ? $this->cumulativeTotals($reports) : [];
        }

        return app('view')->make($this->viewName('index'), [
            'stores' => $stores,
            'reports' => $reports,
            'groups' => $this->view === 'store' && $single === null ? $reports->groupBy('store_id') : null,
            'single' => $single,
            'setting' => $setting,
            'rows' => $rows,
            'cumulative' => $cumulative,
            'reminder' => $reminder,
            'canCreate' => $single !== null && $access->canCreate($user, $single),
            'canSettings' => $single !== null && $access->canManageSettings($user, $single),
            'statuses' => DailyReport::STATUSES,
            'access' => $access,
        ]);
    }

    private function validDate(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && strtotime($value) !== false;
    }

    /**
     * Le rapport du jour apparaît comme « Aucun rapport » à partir de l'heure réglée ; les jours manquants avant aussi.
     *
     * @param  Collection<int, DailyReport>  $reports
     * @return list<array{date: string, report: DailyReport|null}>
     */
    private function rowsWithGaps($reports, DailyReportSetting $setting): array
    {
        $now = Carbon::now((string) config('kintai.timezone', 'UTC'));
        $end = $this->validDate($this->to) ? min($this->to, $now->toDateString()) : ($now->format('H:i') >= $setting->no_report_time ? $now->toDateString() : $now->copy()->subDay()->toDateString());
        $start = $this->validDate($this->from) ? $this->from : $now->copy()->startOfMonth()->toDateString();
        // Les jours sans rapport ne s'affichent que sur la dernière année : une plage démesurée ne fige pas la page.
        $start = max($start, Carbon::parse($end)->subDays(365)->toDateString());

        $byDate = $reports->keyBy(fn (DailyReport $report): string => $report->report_date->toDateString());
        $rows = [];

        // Les rapports hors de la plage affichée (futur, par exemple) restent dans la liste.
        foreach ($reports as $report) {
            $rows[$report->report_date->toDateString()] = ['date' => $report->report_date->toDateString(), 'report' => $report];
        }

        if ($this->status === '' && $start <= $end) {
            for ($cursor = Carbon::parse($start); $cursor->toDateString() <= $end; $cursor->addDay()) {
                $date = $cursor->toDateString();
                $rows[$date] ??= ['date' => $date, 'report' => $byDate->get($date)];
            }
        }

        krsort($rows);

        return array_values($rows);
    }

    /**
     * Cumul au fil des jours pour les magasins qui saisissent le chiffre de chaque jour.
     *
     * @param  Collection<int, DailyReport>  $reports
     * @return array<int, array{sales_total: float, customer_count: int, labor_cost: float, waste_total: float}>
     */
    private function cumulativeTotals($reports): array
    {
        $running = ['sales_total' => 0.0, 'customer_count' => 0, 'labor_cost' => 0.0, 'waste_total' => 0.0];
        $totals = [];

        foreach ($reports->sortBy(fn (DailyReport $report): string => $report->report_date->toDateString()) as $report) {
            $running['sales_total'] += (float) $report->sales_total;
            $running['customer_count'] += $report->customer_count;
            $running['labor_cost'] += (float) $report->labor_cost;
            $running['waste_total'] += (float) $report->waste_total;
            $totals[$report->id] = $running;
        }

        return $totals;
    }

    private function reminderDue(DailyReportSetting $setting): bool
    {
        $now = Carbon::now((string) config('kintai.timezone', 'UTC'));
        $today = $now->toDateString();
        $time = $now->format('H:i');

        return $time >= $setting->reminder_time && $time < $setting->no_report_time
            && ! DailyReport::query()->where('store_id', $setting->store_id)->whereDate('report_date', $today)->exists();
    }
}
