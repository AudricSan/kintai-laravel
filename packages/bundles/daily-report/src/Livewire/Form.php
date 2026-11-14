<?php

namespace Kintai\Bundles\DailyReport\Livewire;

use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Kintai\Bundles\DailyReport\DailyReportAccess;
use Kintai\Bundles\DailyReport\DailyReportActions;
use Kintai\Bundles\DailyReport\DayShifts;
use Kintai\Bundles\DailyReport\Models\DailyReport;
use Kintai\Bundles\DailyReport\Models\DailyReportSetting;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Création et modification d'un rapport (brouillon ou soumis). La création se fait pour un magasin où l'on a
 * `daily_reports.create`, par défaut pour aujourd'hui ; la date ne se change plus ensuite.
 */
#[Title('Daily report')]
class Form extends Component
{
    use ResolvesViews;

    #[Locked]
    public int $reportId = 0;

    /** Modifiable à la création seulement (le droit est revérifié à l'enregistrement). */
    public int $storeId = 0;

    public string $reportDate = '';

    public string $salesTotal = '0';

    public string $customerCount = '0';

    public string $laborCost = '0';

    public string $wasteTotal = '0';

    public string $notes = '';

    public function mount(?int $report = null): void
    {
        $user = $this->user();
        $access = $this->access();

        if ($report !== null) {
            $model = DailyReport::query()->find($report);
            abort_if($model === null || ! $access->canView($user, $model), 404);
            abort_unless($access->canEdit($user, $model), 403);

            $this->reportId = $model->id;
            $this->storeId = $model->store_id;
            $this->reportDate = $model->report_date->toDateString();
            $this->salesTotal = $this->plain($model->sales_total);
            $this->customerCount = (string) $model->customer_count;
            $this->laborCost = $this->plain($model->labor_cost);
            $this->wasteTotal = $this->plain($model->waste_total);
            $this->notes = (string) $model->notes;

            return;
        }

        $stores = $access->storesWith($user, 'daily_reports.create');
        abort_if($stores === [], 403);

        $requested = request()->integer('store');
        $this->storeId = isset($stores[$requested]) ? $requested : (int) array_key_first($stores);
        $this->reportDate = DailyReportActions::today();
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

    private function plain(string $amount): string
    {
        return rtrim(rtrim($amount, '0'), '.') ?: '0';
    }

    public function save(DailyReportActions $actions): void
    {
        $data = $this->validate([
            'reportDate' => ['required', 'date_format:Y-m-d'],
            'salesTotal' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'customerCount' => ['required', 'integer', 'min:0', 'max:1000000'],
            'laborCost' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'wasteTotal' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $fields = [
            'sales_total' => $data['salesTotal'], 'customer_count' => (int) $data['customerCount'],
            'labor_cost' => $data['laborCost'], 'waste_total' => $data['wasteTotal'], 'notes' => $data['notes'] ?? null,
        ];

        if ($this->reportId === 0) {
            try {
                $report = $actions->create($this->user(), $this->storeId, ['report_date' => $data['reportDate']] + $fields);
            } catch (ValidationException $e) {
                // Les erreurs de l'action portent le nom du champ de l'API ; le formulaire a le sien.
                throw ValidationException::withMessages(['reportDate' => $e->errors()['report_date'] ?? $e->getMessage()]);
            }
        } else {
            $report = DailyReport::query()->findOrFail($this->reportId);
            $actions->update($this->user(), $report, $fields);
        }

        Flux::toast(variant: 'success', text: __('Report saved.'));
        $this->redirectRoute('daily-report.show', ['report' => $report->id], navigate: true);
    }

    public function render(): View
    {
        // Le magasin affiché est celui du rapport (modification) ou l'un de ceux où l'on peut créer : jamais un autre.
        if ($this->reportId !== 0) {
            $storeId = (int) DailyReport::query()->whereKey($this->reportId)->value('store_id');
            $stores = $this->access()->activeStores([$storeId]);
        } else {
            $stores = $this->access()->storesWith($this->user(), 'daily_reports.create');
            $storeId = isset($stores[$this->storeId]) ? $this->storeId : 0;
        }

        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->reportDate) === 1 ? $this->reportDate : DailyReportActions::today();

        return app('view')->make($this->viewName('form'), [
            'stores' => $stores,
            'storeId' => $storeId,
            'editing' => $this->reportId !== 0,
            'rows' => $storeId === 0 ? [] : app(DayShifts::class)->forReportDate($storeId, $date),
            'columns' => DailyReportSetting::query()->firstOrNew(['store_id' => $storeId], DailyReportSetting::DEFAULTS)->columns(),
            'owner' => $this->access()->isOwner($this->user()),
            'today' => DailyReportActions::today(),
        ]);
    }
}
