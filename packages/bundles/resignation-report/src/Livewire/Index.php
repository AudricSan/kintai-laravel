<?php

namespace Kintai\Bundles\ResignationReport\Livewire;

use Illuminate\Contracts\View\View;
use Kintai\Bundles\ResignationReport\Models\ResignationReport;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Liste des rapports de démission des magasins où l'on a `resignation_reports.view` (et seulement ceux-là, même en modifiant
 * l'URL), avec filtres magasin, année et mois de démission. Le bouton « Nouveau » ne s'affiche qu'avec `create` ; chaque
 * destination revérifie son droit.
 */
#[Title('Resignation reports')]
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

    /** Recherche partielle sur le responsable. */
    #[Url(except: '')]
    public string $person = '';

    public function mount(): void
    {
        abort_if($this->access()->storesWith($this->user(), 'resignation_reports.view') === [], 403);
    }

    public function render(): View
    {
        $user = $this->user();
        $access = $this->access();
        $stores = $access->storesWith($user, 'resignation_reports.view');
        $single = ctype_digit($this->store) && isset($stores[(int) $this->store]) ? (int) $this->store : null;

        $reports = $access->visible($user)
            ->with('store:id,name')
            ->when($single !== null, fn ($q) => $q->where('store_id', $single))
            ->when(preg_match('/^\d{4}$/', $this->year) === 1, fn ($q) => $q->whereYear('resignation_date', (int) $this->year))
            ->when(ctype_digit($this->month) && (int) $this->month >= 1 && (int) $this->month <= 12, fn ($q) => $q->whereMonth('resignation_date', (int) $this->month))
            ->when(trim($this->person) !== '', fn ($q) => $q->where('person_in_charge', 'like', '%'.trim($this->person).'%'))
            ->orderByDesc('resignation_date')
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get();

        return app('view')->make($this->viewName('index'), [
            'stores' => $stores,
            'reports' => $reports,
            'single' => $single,
            'canCreate' => $access->storesWith($user, 'resignation_reports.create') !== [],
            'years' => ResignationReport::query()->whereIn('store_id', array_keys($stores))->whereNotNull('resignation_date')->selectRaw('distinct substr(resignation_date, 1, 4) as year')->orderByDesc('year')->pluck('year')->all(),
        ]);
    }
}
