<?php

namespace Kintai\Bundles\Analytics\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Route;
use Kintai\Bundles\Analytics\StoreStatsExport;
use Kintai\Bundles\Analytics\StoreStatsService;
use Kintai\Contracts\Sales\DailySalesProvider;
use Livewire\Attributes\Title;

/**
 * Statistiques d'un magasin sur une période : scores, planification, charge de travail, stabilité, congés, coût, jours de la
 * semaine, qualité du planning. L'export passe par les exports du Core (jeu de données `store-stats`, droit `payroll.export`).
 */
#[Title('Store statistics')]
class StoreStats extends StorePage
{
    public function render(StoreStatsService $service): View
    {
        $store = $this->currentStore();
        $period = $this->clampPeriod($this->period);
        $stats = $service->storeStats($store->id, $period, (int) $store->min_shift_minutes, (int) $store->max_shift_minutes);

        return app('view')->make($this->viewName('stats'), [
            'stores' => $this->stores(),
            'storeModel' => $store,
            'periods' => self::PERIODS,
            'stats' => $stats,
            'exportUrl' => $this->access()->canExport($this->user(), $store->id)
                ? route('exports.download', ['dataset' => StoreStatsExport::KEY, 'format' => 'csv', 'target' => 'stores', 'stores' => [$store->id], 'from' => $stats['since'], 'to' => $stats['today']])
                : null,
            'hasSales' => app(DailySalesProvider::class)->available(),
            'dailyReportsUrl' => Route::has('daily-report.index') ? route('daily-report.index', ['store' => $store->id]) : null,
        ]);
    }
}
