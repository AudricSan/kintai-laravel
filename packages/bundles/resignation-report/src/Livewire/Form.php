<?php

namespace Kintai\Bundles\ResignationReport\Livewire;

use App\Models\Store;
use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Kintai\Bundles\ResignationReport\Models\ResignationReport;
use Kintai\Bundles\ResignationReport\ResignationReportActions;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Création (`resignation_reports.create`) et modification (`resignation_reports.update`, sur un rapport visible) d'un rapport
 * de démission. À la création, le magasin se choisit parmi ceux où l'on peut créer ; ensuite il ne change plus. L'employé
 * lié, facultatif, doit appartenir au magasin ; le choisir préremplit numéro et nom (`?user=` à l'ouverture aussi). Le
 * rapport désactive le compte lié à la date de départ : il faut pouvoir modifier cet employé (`employees.update`), et un refus d'un
 * garde-fou du Core (soi-même, dernier Owner) est une erreur de validation qui annule l'enregistrement. Les droits sont
 * revérifiés à l'enregistrement.
 */
#[Title('Resignation report')]
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

        foreach (ResignationReport::FIELDS as $field) {
            $this->values[$field] = '';
        }

        if ($report !== null) {
            $model = ResignationReport::query()->find($report);
            abort_if($model === null || ! $access->canView($user, $model), 404);
            abort_unless($access->canUpdate($user, $model), 403);

            $this->reportId = $model->id;
            $this->storeId = $model->store_id;
            $this->userId = (string) $model->user_id;
            foreach (ResignationReport::FIELDS as $field) {
                $value = $model->{$field};
                $this->values[$field] = $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : (string) $value;
            }

            return;
        }

        $stores = $access->storesWith($user, 'resignation_reports.create');
        abort_if($stores === [], 403);

        $requested = request()->integer('store');
        $requestedUser = request()->integer('user');
        $this->storeId = isset($stores[$requested]) ? $requested : (int) array_key_first($stores);

        // Sans magasin demandé, `?user=` ouvre le formulaire dans le premier magasin permis où l'employé est membre.
        if (! isset($stores[$requested]) && $requestedUser > 0) {
            foreach (array_keys($stores) as $storeId) {
                if (isset($access->members($storeId)[$requestedUser])) {
                    $this->storeId = $storeId;
                    break;
                }
            }
        }
        $this->values['resignation_date'] = now((string) config('kintai.timezone', 'UTC'))->toDateString();
        $this->values['person_in_charge'] = $user->name;

        if ($requestedUser > 0 && isset($access->members($this->storeId)[$requestedUser])) {
            $this->userId = (string) $requestedUser;
            $this->fillFromEmployee();
        }
    }

    /**
     * Un autre magasin, d'autres employés : le choix précédent ne vaut plus.
     */
    public function updatedStoreId(): void
    {
        $this->userId = '';
    }

    /**
     * Choisir un employé du magasin reprend son numéro et son nom (modifiables ensuite) ; le choix est revérifié à
     * l'enregistrement.
     */
    public function updatedUserId(): void
    {
        $this->fillFromEmployee();
    }

    private function fillFromEmployee(): void
    {
        if ($this->userId === '' || ! ctype_digit($this->userId) || ! isset($this->access()->members($this->storeId)[(int) $this->userId])) {
            return;
        }

        $employee = User::query()->find((int) $this->userId);

        if ($employee !== null) {
            $this->values['employee_number'] = (string) $employee->employee_code;
            $this->values['employee_name'] = $employee->name;
        }
    }

    public function save(ResignationReportActions $actions): void
    {
        $this->validate([
            'userId' => ['nullable', 'integer'],
            'values' => ['array'],
            'values.employee_number' => ['nullable', 'string', 'max:255'],
            'values.employee_name' => ['nullable', 'string', 'max:255'],
            'values.resignation_date' => ['nullable', 'date_format:Y-m-d'],
            'values.reason' => ['nullable', 'string', 'max:5000'],
            'values.resignation_notice' => ['nullable', 'string', 'max:5000'],
            'values.notes' => ['nullable', 'string', 'max:5000'],
            'values.person_in_charge' => ['nullable', 'string', 'max:255'],
        ]);

        $employee = $this->userId === '' ? null : (int) $this->userId;

        if ($this->reportId === 0) {
            $report = $actions->create($this->user(), $this->storeId, $employee, $this->values);
        } else {
            $report = ResignationReport::query()->findOrFail($this->reportId);
            $actions->update($this->user(), $report, $employee, $this->values);
        }

        Flux::toast(variant: 'success', text: __('Resignation report saved.'));
        $this->redirectRoute('resignation-report.show', ['report' => $report->id], navigate: true);
    }

    public function render(): View
    {
        $access = $this->access();

        // Le magasin affiché est celui du rapport (modification) ou l'un de ceux où l'on peut créer : jamais un autre.
        if ($this->reportId !== 0) {
            $stores = $access->activeStores([$this->storeId]);
            $storeId = $this->storeId;
        } else {
            $stores = $access->storesWith($this->user(), 'resignation_reports.create');
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
