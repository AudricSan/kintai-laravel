<?php

namespace Kintai\Bundles\SalaryReport\Livewire;

use App\Models\Store;
use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Kintai\Bundles\SalaryReport\Models\SalaryReport;
use Kintai\Bundles\SalaryReport\SalaryReportActions;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Création et modification d'un rapport (`salary_reports.generate` sur le magasin). À la création, le magasin, l'employé
 * éventuel et la période (un mois, ou une plage dont le mois du premier jour devient le mois du rapport) sont choisis et
 * les valeurs sont calculées depuis les shifts ; ensuite le magasin, le mois et l'employé ne changent plus. « Recalculer »
 * remplace les valeurs par un nouveau calcul. Le détail du calcul est affiché, jamais enregistré par le client.
 */
#[Title('Salary report')]
class Form extends Component
{
    use ResolvesViews;

    private const NUMBERS = [...SalaryReport::AMOUNTS, ...SalaryReport::HOURS, ...SalaryReport::COUNTS];

    #[Locked]
    public int $reportId = 0;

    /** Modifiable à la création seulement (le droit est revérifié à chaque calcul et à l'enregistrement). */
    public int $storeId = 0;

    /** Identifiant de l'employé du rapport, vide pour le magasin entier ; à la création seulement. */
    public string $employeeId = '';

    /** « month » ou « custom ». */
    public string $periodMode = 'month';

    public string $month = '';

    public string $from = '';

    public string $to = '';

    public string $personInChargeId = '';

    /** @var array<string, string> */
    public array $values = [];

    public string $hireRegistrations = '';

    public string $remarks = '';

    /** @var list<array{user_id: int, minutes: int}> */
    #[Locked]
    public array $employeeHours = [];

    /** @var array<string, mixed> */
    #[Locked]
    public array $detail = [];

    public function mount(?int $report = null): void
    {
        $user = $this->user();
        $access = $this->access();

        if ($report !== null) {
            $model = SalaryReport::query()->find($report);
            abort_if($model === null || ! $access->canView($user, $model), 404);
            abort_unless($access->canEdit($user, $model), 403);

            $this->reportId = $model->id;
            $this->storeId = $model->store_id;
            $this->employeeId = (string) $model->user_id;
            $this->month = $model->target_month;
            $this->personInChargeId = (string) $model->person_in_charge_id;
            foreach (self::NUMBERS as $field) {
                $this->values[$field] = $this->plain((string) $model->{$field});
            }
            $this->hireRegistrations = (string) $model->hire_registrations;
            $this->remarks = (string) $model->remarks;
            $this->employeeHours = $model->employee_hours ?? [];

            return;
        }

        $stores = $access->storesWith($user, 'salary_reports.generate');
        abort_if($stores === [], 403);

        $requested = request()->integer('store');
        $this->storeId = isset($stores[$requested]) ? $requested : (int) array_key_first($stores);
        $this->employeeId = request()->integer('employee') > 0 && isset($access->members($this->storeId)[request()->integer('employee')]) ? (string) request()->integer('employee') : '';
        $this->month = Carbon::now((string) config('kintai.timezone', 'UTC'))->format('Y-m');
        $this->from = Carbon::now((string) config('kintai.timezone', 'UTC'))->startOfMonth()->toDateString();
        $this->to = Carbon::now((string) config('kintai.timezone', 'UTC'))->endOfMonth()->toDateString();
        foreach (self::NUMBERS as $field) {
            $this->values[$field] = '0';
        }

        $this->personInChargeId = isset($access->managers($this->storeId)[$user->id]) ? (string) $user->id : '';
        $this->calculate();
    }

    private function plain(string $amount): string
    {
        return str_contains($amount, '.') ? (rtrim(rtrim($amount, '0'), '.') ?: '0') : $amount;
    }

    /**
     * À la création, un changement de magasin, d'employé ou de période recalcule les valeurs.
     */
    public function updated(string $name): void
    {
        if ($this->reportId !== 0 || ! in_array($name, ['storeId', 'employeeId', 'periodMode', 'month', 'from', 'to'], true)) {
            return;
        }

        if ($name === 'storeId') {
            $this->employeeId = '';
            $this->personInChargeId = isset($this->access()->managers($this->storeId)[$this->user()->id]) ? (string) $this->user()->id : '';
        }

        $this->calculate();
    }

    /**
     * Remplace les valeurs et le détail par un nouveau calcul (ancien rapport : sur son mois, magasin et employé).
     */
    public function calculate(): void
    {
        if ($this->reportId !== 0) {
            $report = SalaryReport::query()->findOrFail($this->reportId);
            [$from, $to] = $report->period();
            $storeId = $report->store_id;
            $employee = $report->user_id;
        } else {
            [$from, $to] = $this->range();
            $storeId = $this->storeId;
            $employee = $this->employeeId === '' ? null : (int) $this->employeeId;
        }

        try {
            $result = app(SalaryReportActions::class)->calculate($this->user(), $storeId, $from, $to, $employee);
        } catch (ValidationException $e) {
            $this->detail = [];

            // L'erreur de l'action porte le nom du champ de l'API ; le formulaire a le sien.
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn (array $messages, string $key): array => [$key === 'employee' ? 'employeeId' : $key => $messages])->all());
        }

        $preset = $result['preset'];
        foreach (self::NUMBERS as $field) {
            $this->values[$field] = $this->plain((string) ($preset[$field] ?? 0));
        }
        /** @var list<array{user_id: int, minutes: int}> $hours */
        $hours = $preset['employee_hours'];
        $this->employeeHours = $hours;
        $this->detail = $result['detail'];
        $this->resetErrorBag();
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function range(): array
    {
        if ($this->periodMode === 'custom') {
            return [$this->from, $this->to];
        }

        $first = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->month) === 1 ? Carbon::parse($this->month.'-01') : Carbon::now((string) config('kintai.timezone', 'UTC'))->startOfMonth();

        return [$first->toDateString(), $first->copy()->endOfMonth()->toDateString()];
    }

    public function save(SalaryReportActions $actions): void
    {
        $rules = [
            'values' => ['array'],
            'personInChargeId' => ['nullable', 'integer'],
            'hireRegistrations' => ['nullable', 'string', 'max:5000'],
            'remarks' => ['nullable', 'string', 'max:5000'],
        ];
        foreach ([...SalaryReport::AMOUNTS, ...SalaryReport::HOURS] as $field) {
            $rules['values.'.$field] = ['required', 'numeric', 'min:0', 'max:9999999999'];
        }
        foreach (SalaryReport::COUNTS as $field) {
            $rules['values.'.$field] = ['required', 'integer', 'min:0', 'max:1000000'];
        }
        if ($this->reportId === 0) {
            $rules['periodMode'] = ['required', Rule::in(['month', 'custom'])];
            if ($this->periodMode === 'custom') {
                $rules['from'] = ['required', 'date_format:Y-m-d'];
                $rules['to'] = ['required', 'date_format:Y-m-d'];
            } else {
                $rules['month'] = ['required', 'date_format:Y-m'];
            }
        }
        $this->validate($rules);

        $data = $this->values + [
            'person_in_charge_id' => $this->personInChargeId === '' ? null : (int) $this->personInChargeId,
            'hire_registrations' => $this->hireRegistrations, 'remarks' => $this->remarks,
            'employee_hours' => $this->employeeHours,
        ];

        try {
            if ($this->reportId === 0) {
                [$from] = $this->range();
                $report = $actions->create($this->user(), $this->storeId, substr($from, 0, 7), $this->employeeId === '' ? null : (int) $this->employeeId, $data);
            } else {
                $report = SalaryReport::query()->findOrFail($this->reportId);
                $actions->update($this->user(), $report, $data);
            }
        } catch (ValidationException $e) {
            // Les erreurs de l'action portent le nom du champ de l'API ; le formulaire a les siens.
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn (array $messages, string $key): array => [match ($key) {
                'person_in_charge_id' => 'personInChargeId', 'employee' => 'employeeId', default => 'month'
            } => $messages])->all());
        }

        Flux::toast(variant: 'success', text: __('Salary report saved.'));
        $this->redirectRoute('salary-report.show', ['report' => $report->id], navigate: true);
    }

    public function render(): View
    {
        $access = $this->access();

        // Le magasin affiché est celui du rapport (modification) ou l'un de ceux où l'on peut générer : jamais un autre.
        if ($this->reportId !== 0) {
            $report = SalaryReport::query()->with(['store', 'employee'])->findOrFail($this->reportId);
            $stores = $access->activeStores([$report->store_id]);
            $storeId = $report->store_id;
        } else {
            $report = null;
            $stores = $access->storesWith($this->user(), 'salary_reports.generate');
            $storeId = isset($stores[$this->storeId]) ? $this->storeId : 0;
        }

        $store = $storeId === 0 ? null : Store::query()->find($storeId);

        return app('view')->make($this->viewName('form'), [
            'stores' => $stores,
            'storeId' => $storeId,
            'store' => $store,
            'report' => $report,
            'editing' => $this->reportId !== 0,
            'members' => $storeId === 0 ? [] : $access->members($storeId),
            'managers' => $storeId === 0 ? [] : $access->managers($storeId),
            'names' => User::query()->withTrashed()->whereIn('id', array_column($this->employeeHours, 'user_id'))->pluck('name', 'id')->all(),
        ]);
    }
}
