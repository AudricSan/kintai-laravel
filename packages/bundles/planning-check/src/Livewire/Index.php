<?php

namespace Kintai\Bundles\PlanningCheck\Livewire;

use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Kintai\Bundles\PlanningCheck\DaySchedule;
use Kintai\Bundles\PlanningCheck\Models\PlanningCheck;
use Kintai\Bundles\PlanningCheck\Models\PlanningCheckSetting;
use Kintai\Bundles\PlanningCheck\PlanningCheckAccess;
use Kintai\Bundles\PlanningCheck\PlanningCheckActions;
use Kintai\Bundles\PlanningCheck\PlanningCheckPolicy;
use Kintai\Contracts\Notifications\Notifier;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Validation du planning de demain d'un magasin : l'état de demain avec l'aperçu des shifts et les actions selon les droits
 * (confirmer, valider et envoyer le PDF, refuser avec un motif, envoyer maintenant, rouvrir), puis l'historique des
 * validations. Droits revérifiés à chaque action (voir `PlanningCheckAccess` et `PlanningCheckActions`).
 */
#[Title('Tomorrow\'s schedule')]
class Index extends Component
{
    use ResolvesViews;

    private const HISTORY = 40;

    #[Url(except: '')]
    public string $store = '';

    /** Validation désignée par une notification. */
    #[Url(except: 0)]
    public int $highlight = 0;

    public bool $confirmEmpty = false;

    public string $reason = '';

    #[Locked]
    public int $targetId = 0;

    public function mount(): void
    {
        $stores = $this->access()->visibleStores($this->user());
        abort_if($stores === [], 404);

        $this->store = ctype_digit($this->store) && isset($stores[(int) $this->store]) ? $this->store : (string) array_key_first($stores);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    private function access(): PlanningCheckAccess
    {
        return app(PlanningCheckAccess::class);
    }

    private function storeId(): int
    {
        abort_unless(isset($this->access()->visibleStores($this->user())[(int) $this->store]), 404);

        return (int) $this->store;
    }

    private function find(int $id): PlanningCheck
    {
        return PlanningCheck::query()->where('store_id', $this->storeId())->findOrFail($id);
    }

    public function confirm(string $action, int $id = 0): void
    {
        abort_unless(in_array($action, ['approve', 'reject', 'send-now', 'reopen', 'resend'], true), 404);

        $this->targetId = $id;
        $this->reason = '';
        Flux::modal('confirm-planning-check-'.$action)->show();
    }

    public function submit(PlanningCheckActions $actions): void
    {
        $actions->submit($this->user(), $this->storeId(), $this->confirmEmpty);

        $this->confirmEmpty = false;
        Flux::toast(variant: 'success', text: __('Schedule submitted: a validator will review it.'));
    }

    public function approve(PlanningCheckActions $actions): void
    {
        $check = $actions->approve($this->user(), $this->find($this->targetId));

        Flux::modal('confirm-planning-check-approve')->close();
        $this->toastSend($check);
    }

    public function reject(PlanningCheckActions $actions): void
    {
        $this->validate(['reason' => ['required', 'string', 'max:500']]);

        $actions->reject($this->user(), $this->find($this->targetId), $this->reason);

        Flux::modal('confirm-planning-check-reject')->close();
        Flux::toast(variant: 'success', text: __('Schedule refused: the submitter is notified.'));
    }

    public function sendNow(PlanningCheckActions $actions): void
    {
        $check = $actions->sendNow($this->user(), $this->storeId(), $this->confirmEmpty);

        $this->confirmEmpty = false;
        Flux::modal('confirm-planning-check-send-now')->close();
        $this->toastSend($check);
    }

    public function resend(PlanningCheckActions $actions): void
    {
        $actions->resend($this->user(), $this->find($this->targetId));

        Flux::modal('confirm-planning-check-resend')->close();
        Flux::toast(variant: 'success', text: __('PDF sent again.'));
    }

    public function reopen(PlanningCheckActions $actions): void
    {
        $actions->reopen($this->user(), $this->find($this->targetId));

        Flux::modal('confirm-planning-check-reopen')->close();
        Flux::toast(variant: 'success', text: __('The check is reopened: read tomorrow\'s shifts again.'));
    }

    private function toastSend(PlanningCheck $check): void
    {
        if ($check->send_status === 'failed') {
            Flux::toast(variant: 'warning', text: __('The schedule is validated but the PDF could not be sent: try again from the history.'));

            return;
        }

        Flux::toast(variant: 'success', text: __('The schedule is validated and sent.'));
    }

    public function render(): View
    {
        $user = $this->user();
        $access = $this->access();
        $storeId = $this->storeId();
        $actions = app(PlanningCheckActions::class);
        $schedule = app(DaySchedule::class);

        $setting = PlanningCheckSetting::forStore($storeId);
        $date = PlanningCheckPolicy::targetDate();
        $tomorrow = PlanningCheck::query()->where('store_id', $storeId)->whereDate('target_date', $date)->first();
        $day = $schedule->forDay($storeId, $date, $setting->include_open_shifts);

        $history = PlanningCheck::query()
            ->with(['submitter:id,name', 'validator:id,name', 'rejecter:id,name'])
            ->where('store_id', $storeId)->orderByDesc('target_date')->limit(self::HISTORY)->get();

        // Les notifications de ces validations sont lues à la visite de la page.
        if ($history->isNotEmpty()) {
            app(Notifier::class)->markRead($user, 'planning_check', $history->modelKeys());
        }

        $open = $tomorrow === null || $tomorrow->isOpen();

        return app('view')->make($this->viewName('index'), [
            'stores' => $access->visibleStores($user),
            'setting' => $setting,
            'date' => $date,
            'tomorrow' => $tomorrow,
            'day' => $day,
            'shiftCount' => $day['assigned'] + $day['open'],
            'working' => PlanningCheckPolicy::isWorkingDay($setting, $date),
            'late' => PlanningCheckPolicy::isLate($setting) && $open,
            'history' => $history,
            'resend' => $history->mapWithKeys(fn (PlanningCheck $check): array => [$check->id => $actions->needsResend($check)])->all(),
            'canSubmit' => $access->allows($user, 'planning_check.submit', $storeId),
            'canApprove' => $access->allows($user, 'planning_check.approve', $storeId),
            'canSettings' => $access->allows($user, 'planning_check.settings', $storeId),
            'canEditSchedule' => $user->can('shifts.view'),
            'timezone' => (string) config('kintai.timezone', 'UTC'),
        ]);
    }
}
