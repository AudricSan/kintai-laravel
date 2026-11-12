<?php

namespace Kintai\Bundles\SalaryReport\Livewire;

use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Kintai\Bundles\SalaryReport\Models\SalaryReport;
use Kintai\Bundles\SalaryReport\SalaryCalculator;
use Kintai\Bundles\SalaryReport\SalaryReportActions;
use Kintai\Contracts\Activity\ActivityRecorder;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Un rapport (`salary_reports.view` sur son magasin ; un rapport hors du périmètre est introuvable) : montants, effectifs,
 * heures par employé et, pour un rapport d'employé, le détail jour par jour relu sur ses shifts. Modifier, PDF et supprimer
 * ne s'affichent qu'avec leur permission, chacun la revérifie. L'ouverture est journalisée.
 */
#[Title('Salary report')]
class Show extends Component
{
    use ResolvesViews;

    #[Locked]
    public int $reportId = 0;

    public function mount(int $report, ActivityRecorder $activity): void
    {
        $model = SalaryReport::query()->find($report);
        abort_if($model === null || ! $this->access()->canView($this->user(), $model), 404);

        $this->reportId = $model->id;
        $activity->record('salary_report.viewed', $model, $model->store_id, ['month' => $model->target_month], $this->user());
    }

    private function report(): SalaryReport
    {
        $report = SalaryReport::query()->with(['store', 'employee', 'personInCharge'])->find($this->reportId);
        abort_if($report === null || ! $this->access()->canView($this->user(), $report), 404);

        return $report;
    }

    public function confirmDelete(): void
    {
        abort_unless($this->access()->canDelete($this->user(), $this->report()), 403);

        Flux::modal('confirm-salary-report-delete')->show();
    }

    public function delete(SalaryReportActions $actions): void
    {
        $report = $this->report();
        $actions->delete($this->user(), $report);

        Flux::toast(variant: 'success', text: __('Salary report deleted.'));
        $this->redirectRoute('salary-report.index', ['store' => $report->store_id], navigate: true);
    }

    public function render(SalaryCalculator $calculator): View
    {
        $user = $this->user();
        $access = $this->access();
        $report = $this->report();

        return app('view')->make($this->viewName('show'), [
            'report' => $report,
            'detail' => $calculator->detailFor($report),
            'names' => User::query()->withTrashed()->whereIn('id', array_column($report->employee_hours ?? [], 'user_id'))->pluck('name', 'id')->all(),
            'canEdit' => $access->canEdit($user, $report),
            'canDelete' => $access->canDelete($user, $report),
            'canExport' => $access->canExport($user, $report),
        ]);
    }
}
