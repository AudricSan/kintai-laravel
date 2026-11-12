<?php

namespace Kintai\Bundles\HiringReport\Livewire;

use App\Models\Store;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Kintai\Bundles\HiringReport\HiringReportActions;
use Kintai\Bundles\HiringReport\Models\HiringReport;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Création (`hiring_reports.create`) et modification (`hiring_reports.update`, sur un rapport visible) d'un rapport
 * d'embauche. À la création, le magasin se choisit parmi ceux où l'on peut créer ; ensuite il ne change plus. L'employé
 * lié, facultatif, doit appartenir au magasin et n'avoir qu'un rapport dans ce magasin. Les droits sont revérifiés à
 * l'enregistrement.
 */
#[Title('Hiring report')]
class Form extends Component
{
    use ResolvesViews;

    #[Locked]
    public int $reportId = 0;

    /** Modifiable à la création seulement (le droit est revérifié à l'enregistrement). */
    public int $storeId = 0;

    /** Identifiant de l'employé lié, vide si aucun. */
    public string $userId = '';

    /** @var array<string, string> */
    public array $values = [];

    public function mount(?int $report = null): void
    {
        $user = $this->user();
        $access = $this->access();

        foreach (HiringReport::FIELDS as $field) {
            $this->values[$field] = '';
        }

        if ($report !== null) {
            $model = HiringReport::query()->find($report);
            abort_if($model === null || ! $access->canView($user, $model), 404);
            abort_unless($access->canUpdate($user, $model), 403);

            $this->reportId = $model->id;
            $this->storeId = $model->store_id;
            $this->userId = (string) $model->user_id;
            foreach (HiringReport::FIELDS as $field) {
                $value = $model->{$field};
                $this->values[$field] = $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : (string) $value;
            }

            return;
        }

        $stores = $access->storesWith($user, 'hiring_reports.create');
        abort_if($stores === [], 403);

        $requested = request()->integer('store');
        $this->storeId = isset($stores[$requested]) ? $requested : (int) array_key_first($stores);
        $this->values['hire_date'] = now((string) config('kintai.timezone', 'UTC'))->toDateString();
        $this->values['hired_by'] = $user->name;
    }

    /**
     * Un autre magasin, d'autres employés : le choix précédent ne vaut plus.
     */
    public function updatedStoreId(): void
    {
        $this->userId = '';
    }

    public function save(HiringReportActions $actions): void
    {
        $this->validate([
            'userId' => ['nullable', 'integer'],
            'values' => ['array'],
            'values.employee_number' => ['nullable', 'string', 'max:255'],
            'values.employee_name' => ['nullable', 'string', 'max:255'],
            'values.furigana_last_name' => ['nullable', 'string', 'max:255'],
            'values.furigana_first_name' => ['nullable', 'string', 'max:255'],
            'values.gender' => ['nullable', Rule::in(['male', 'female', 'other'])],
            'values.tax_classification' => ['nullable', 'string', 'max:100'],
            'values.birth_date' => ['nullable', 'date_format:Y-m-d'],
            'values.hire_date' => ['nullable', 'date_format:Y-m-d'],
            'values.education' => ['nullable', 'string', 'max:255'],
            'values.postal_code' => ['nullable', 'string', 'max:255'],
            'values.address' => ['nullable', 'string', 'max:2000'],
            'values.phone' => ['nullable', 'string', 'max:255'],
            'values.mobile_phone' => ['nullable', 'string', 'max:255'],
            'values.email' => ['nullable', 'string', 'email', 'max:255'],
            'values.guarantor_name' => ['nullable', 'string', 'max:255'],
            'values.guarantor_phone' => ['nullable', 'string', 'max:255'],
            'values.store_name' => ['nullable', 'string', 'max:255'],
            'values.hired_by' => ['nullable', 'string', 'max:255'],
            'values.notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $employee = $this->userId === '' ? null : (int) $this->userId;

        if ($this->reportId === 0) {
            $report = $actions->create($this->user(), $this->storeId, $employee, $this->values);
        } else {
            $report = HiringReport::query()->findOrFail($this->reportId);
            $actions->update($this->user(), $report, $employee, $this->values);
        }

        Flux::toast(variant: 'success', text: __('Hiring report saved.'));
        $this->redirectRoute('hiring-report.show', ['report' => $report->id], navigate: true);
    }

    public function render(): View
    {
        $access = $this->access();

        // Le magasin affiché est celui du rapport (modification) ou l'un de ceux où l'on peut créer : jamais un autre.
        if ($this->reportId !== 0) {
            $stores = $access->activeStores([$this->storeId]);
            $storeId = $this->storeId;
        } else {
            $stores = $access->storesWith($this->user(), 'hiring_reports.create');
            $storeId = isset($stores[$this->storeId]) ? $this->storeId : 0;
        }

        return app('view')->make($this->viewName('form'), [
            'stores' => $stores,
            'storeId' => $storeId,
            'store' => $storeId === 0 ? null : Store::query()->find($storeId),
            'editing' => $this->reportId !== 0,
            'reportId' => $this->reportId,
            'members' => $storeId === 0 ? [] : $access->members($storeId),
        ]);
    }
}
