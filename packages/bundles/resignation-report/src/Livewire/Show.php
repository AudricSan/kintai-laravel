<?php

namespace Kintai\Bundles\ResignationReport\Livewire;

use Flux\Flux;
use Illuminate\Contracts\View\View;
use Kintai\Bundles\ResignationReport\Models\ResignationReport;
use Kintai\Bundles\ResignationReport\ResignationReportActions;
use Kintai\Contracts\Activity\ActivityRecorder;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Un rapport (`resignation_reports.view` sur son magasin ; un rapport hors du périmètre est introuvable). Modifier et
 * supprimer ne s'affichent qu'avec leur permission, chacun la revérifie. L'ouverture est journalisée, sans donnée du
 * rapport.
 */
#[Title('Resignation report')]
class Show extends Component
{
    use ResolvesViews;

    #[Locked]
    public int $reportId = 0;

    public function mount(int $report, ActivityRecorder $activity): void
    {
        $model = ResignationReport::query()->find($report);
        abort_if($model === null || ! $this->access()->canView($this->user(), $model), 404);

        $this->reportId = $model->id;
        $activity->record('resignation_report.viewed', $model, $model->store_id, ['employee_id' => $model->user_id], $this->user());
    }

    private function report(): ResignationReport
    {
        $report = ResignationReport::query()->with(['store', 'creator'])->find($this->reportId);
        abort_if($report === null || ! $this->access()->canView($this->user(), $report), 404);

        return $report;
    }

    public function confirmDelete(): void
    {
        abort_unless($this->access()->canDelete($this->user(), $this->report()), 403);

        Flux::modal('confirm-resignation-report-delete')->show();
    }

    public function delete(ResignationReportActions $actions): void
    {
        $report = $this->report();
        $actions->delete($this->user(), $report);

        Flux::toast(variant: 'success', text: __('Resignation report deleted.'));
        $this->redirectRoute('resignation-report.index', ['store' => $report->store_id], navigate: true);
    }

    public function render(): View
    {
        $user = $this->user();
        $access = $this->access();
        $report = $this->report();

        return app('view')->make($this->viewName('show'), [
            'report' => $report,
            'canUpdate' => $access->canUpdate($user, $report),
            'canDelete' => $access->canDelete($user, $report),
        ]);
    }
}
