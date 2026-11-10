<?php

namespace Kintai\Bundles\Analytics\Livewire;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Route;
use Kintai\Bundles\Analytics\ProfitabilityService;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Throwable;

/**
 * Rentabilité d'un magasin : ventes, clients et coût du personnel (saisi dans les rapports journaliers, et calculé depuis
 * les shifts) sur une période libre ou un raccourci. Sans fournisseur de ventes actif, la page l'explique sans rien calculer.
 */
#[Title('Profitability')]
class Profitability extends StorePage
{
    /** Écart maximal entre les deux dates, en jours. */
    public const MAX_DAYS = 366;

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    public function mount(): void
    {
        parent::mount();

        [$this->from, $this->to] = $this->range();
    }

    public function setPreset(int $days): void
    {
        $days = in_array($days, self::PERIODS, true) ? $days : 90;
        $today = $this->today();

        $this->from = $today->subDays($days)->toDateString();
        $this->to = $today->toDateString();
    }

    public function render(ProfitabilityService $service): View
    {
        $store = $this->currentStore();
        [$from, $to] = $this->range();

        return app('view')->make($this->viewName('profitability'), [
            'stores' => $this->stores(),
            'storeModel' => $store,
            'periods' => self::PERIODS,
            'from' => $from,
            'to' => $to,
            'data' => $service->storeProfitability($store->id, $from, $to),
            'dailyReportsUrl' => Route::has('daily-report.index') ? route('daily-report.index', ['store' => $store->id]) : null,
        ]);
    }

    /**
     * Les deux dates en « AAAA-MM-JJ », valides, dans l'ordre et à au plus un an d'écart (90 jours à défaut).
     *
     * @return array{0: string, 1: string}
     */
    private function range(): array
    {
        $today = $this->today();
        $to = $this->day($this->to) ?? $today;
        $from = $this->day($this->from) ?? $to->subDays(90);

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        if ($from->diffInDays($to) > self::MAX_DAYS) {
            $from = $to->subDays(self::MAX_DAYS);
        }

        return [$from->toDateString(), $to->toDateString()];
    }

    private function day(string $value): ?CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            $day = CarbonImmutable::createFromFormat('!Y-m-d', $value, (string) config('kintai.timezone', 'UTC'));
        } catch (Throwable) {
            return null;
        }

        return $day !== null && $day->format('Y-m-d') === $value ? $day : null;
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::now((string) config('kintai.timezone', 'UTC'))->startOfDay();
    }
}
