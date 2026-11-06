<?php

namespace Kintai\Bundles\DailyReport\Livewire;

use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Kintai\Bundles\DailyReport\DailyReportAccess;
use Kintai\Bundles\DailyReport\DailyReportActions;
use Kintai\Bundles\DailyReport\DailyReportMailer;
use Kintai\Bundles\DailyReport\DayShifts;
use Kintai\Bundles\DailyReport\Models\DailyReport;
use Kintai\Bundles\DailyReport\Models\DailyReportSetting;
use Kintai\Contracts\Notifications\Notifier;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Un rapport : chiffres, notes, planning du lendemain, suivi (soumission, validation, envoi) et actions selon les droits
 * (modifier, soumettre, valider, envoyer par e-mail, supprimer), chacune confirmée dans une fenêtre.
 */
#[Title('Daily report')]
class Show extends Component
{
    use ResolvesViews;

    #[Locked]
    public int $reportId = 0;

    public function mount(int $report): void
    {
        $model = DailyReport::query()->find($report);
        abort_if($model === null || ! $this->access()->canView($this->user(), $model), 404);

        $this->reportId = $model->id;
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

    private function report(): DailyReport
    {
        $report = DailyReport::query()->with(['store', 'author', 'validator'])->find($this->reportId);
        abort_if($report === null || ! $this->access()->canView($this->user(), $report), 404);

        return $report;
    }

    public function confirm(string $action): void
    {
        abort_unless(in_array($action, ['submit', 'validate', 'mail', 'delete'], true), 404);

        Flux::modal('confirm-daily-report-'.$action)->show();
    }

    public function submit(DailyReportActions $actions): void
    {
        $actions->submit($this->user(), $this->report());

        Flux::modal('confirm-daily-report-submit')->close();
        Flux::toast(variant: 'success', text: __('Report submitted: a validator will review it.'));
    }

    public function validateReport(DailyReportActions $actions): void
    {
        $actions->validate($this->user(), $this->report());

        Flux::modal('confirm-daily-report-validate')->close();
        Flux::toast(variant: 'success', text: __('Report validated.'));
    }

    public function sendMail(DailyReportActions $actions): void
    {
        Flux::modal('confirm-daily-report-mail')->close();
        $actions->sendMail($this->user(), $this->report());

        Flux::toast(variant: 'success', text: __('Report sent by email.'));
    }

    public function delete(DailyReportActions $actions): void
    {
        $report = $this->report();
        $actions->delete($this->user(), $report);

        Flux::toast(variant: 'success', text: __('Report deleted.'));
        $this->redirectRoute('daily-report.index', ['store' => $report->store_id], navigate: true);
    }

    public function render(): View
    {
        $user = $this->user();
        $access = $this->access();
        $report = $this->report();

        // Le rapport est ouvert par quelqu'un qui peut le voir : ses notifications sont lues.
        app(Notifier::class)->markRead($user, 'daily_report', $report->id);

        return app('view')->make($this->viewName('show'), [
            'report' => $report,
            'setting' => DailyReportSetting::forStore($report->store_id),
            'rows' => app(DayShifts::class)->forReportDate($report->store_id, $report->report_date),
            'canEdit' => $access->canEdit($user, $report),
            'canSubmit' => $access->canSubmit($user, $report),
            'canValidate' => $access->canValidate($user, $report),
            'canSendMail' => $access->canSendMail($user, $report),
            'canDelete' => $access->canDelete($user, $report),
            'noRecipients' => $access->canSendMail($user, $report) && app(DailyReportMailer::class)->recipients($report->store_id) === [],
            'canSettings' => $access->canManageSettings($user, $report->store_id),
        ]);
    }
}
