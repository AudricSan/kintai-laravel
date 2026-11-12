<?php

namespace Kintai\Bundles\SalaryReport\Livewire;

use Illuminate\Contracts\View\View;
use Kintai\Bundles\SalaryReport\Models\SalaryReport;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Liste des rapports de salaire des magasins où l'on a `salary_reports.view` (et seulement ceux-là, même en modifiant
 * l'URL), avec filtres magasin, année, mois et employé. Les boutons (nouveau rapport, exports, réglages) ne s'affichent que
 * pour qui a la permission correspondante ; chaque destination la revérifie.
 */
#[Title('Salary reports')]
class Index extends Component
{
    use ResolvesViews;

    private const LIMIT = 500;

    #[Url(except: '')]
    public string $store = '';

    #[Url(except: '')]
    public string $year = '';

    #[Url(except: '')]
    public string $month = '';

    /** Identifiant d'un employé, ou « 0 » pour les rapports de magasin entier. */
    #[Url(except: '')]
    public string $employee = '';

    public function mount(): void
    {
        abort_if($this->access()->storesWith($this->user(), 'salary_reports.view') === [], 403);
    }

    public function render(): View
    {
        $user = $this->user();
        $access = $this->access();
        $stores = $access->storesWith($user, 'salary_reports.view');
        $single = ctype_digit($this->store) && isset($stores[(int) $this->store]) ? (int) $this->store : null;

        $reports = $access->visible($user)
            ->with(['store:id,name,currency,currency_symbol_style', 'employee:id,name'])
            ->when($single !== null, fn ($q) => $q->where('store_id', $single))
            ->when(preg_match('/^\d{4}$/', $this->year) === 1, fn ($q) => $q->where('target_month', 'like', $this->year.'-%'))
            ->when(ctype_digit($this->month) && (int) $this->month >= 1 && (int) $this->month <= 12, fn ($q) => $q->where('target_month', 'like', '%-'.str_pad($this->month, 2, '0', STR_PAD_LEFT)))
            ->when($this->employee === '0', fn ($q) => $q->whereNull('user_id'))
            ->when(ctype_digit($this->employee) && $this->employee !== '0', fn ($q) => $q->where('user_id', (int) $this->employee))
            ->orderByDesc('target_month')
            ->orderBy('store_id')
            ->orderBy('id')
            ->limit(self::LIMIT)
            ->get();

        return app('view')->make($this->viewName('index'), [
            'stores' => $stores,
            'reports' => $reports,
            'employees' => $single === null ? [] : $access->members($single),
            'single' => $single,
            'canGenerate' => $access->storesWith($user, 'salary_reports.generate') !== [],
            'canExport' => $access->exportableStores($user) !== [],
            'canSettings' => $access->storesWith($user, 'salary_reports.settings') !== [],
            'access' => $access,
            'years' => SalaryReport::query()->whereIn('store_id', array_keys($stores))->selectRaw('distinct substr(target_month, 1, 4) as year')->orderByDesc('year')->pluck('year')->all(),
        ]);
    }
}
