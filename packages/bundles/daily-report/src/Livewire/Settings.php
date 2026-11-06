<?php

namespace Kintai\Bundles\DailyReport\Livewire;

use App\Models\StoreMembership;
use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Kintai\Bundles\DailyReport\DailyReportAccess;
use Kintai\Bundles\DailyReport\DailyReportActions;
use Kintai\Bundles\DailyReport\Models\DailyReportSetting;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Réglages des rapports d'un magasin (`daily_reports.update` sur le magasin) : destinataires de l'envoi, envoi automatique à
 * la validation, validation automatique de la veille, heures du rappel, colonnes du planning, mode de calcul.
 */
#[Title('Daily report settings')]
class Settings extends Component
{
    use ResolvesViews;

    #[Url(except: '')]
    public string $store = '';

    public bool $autoSend = false;

    public string $autoValidateTime = '';

    public string $reminderTime = '15:00';

    public string $noReportTime = '18:00';

    /** @var list<string> */
    public array $columns = [];

    public string $mode = 'per_day';

    /** @var list<int|string> */
    public array $recipients = [];

    public function mount(): void
    {
        $stores = $this->stores();
        abort_if($stores === [], 403);

        $this->store = ctype_digit($this->store) && isset($stores[(int) $this->store]) ? $this->store : (string) array_key_first($stores);
        $this->load();
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    /**
     * @return array<int, string>
     */
    private function stores(): array
    {
        return app(DailyReportAccess::class)->storesWith($this->user(), 'daily_reports.update');
    }

    public function updatedStore(): void
    {
        abort_unless(isset($this->stores()[(int) $this->store]), 403);

        $this->load();
    }

    private function load(): void
    {
        $setting = DailyReportSetting::forStore((int) $this->store);

        $this->autoSend = $setting->auto_send_on_validate;
        $this->autoValidateTime = (string) $setting->auto_validate_time;
        $this->reminderTime = $setting->reminder_time;
        $this->noReportTime = $setting->no_report_time;
        $this->columns = $setting->columns();
        $this->mode = $setting->cumulative_mode;
        $this->recipients = $setting->recipientIds();
    }

    /**
     * Employés actifs du magasin qui ont une adresse e-mail.
     *
     * @return array<int, string>
     */
    private function candidates(): array
    {
        $members = StoreMembership::query()->where('store_id', (int) $this->store)->where('is_active', true)->pluck('user_id');
        $candidates = [];

        foreach (User::query()->whereIn('id', $members)->where('is_active', true)->whereNotNull('email')->orderBy('name')->get(['id', 'name']) as $user) {
            $candidates[$user->id] = $user->name;
        }

        return $candidates;
    }

    public function save(DailyReportActions $actions): void
    {
        abort_unless(isset($this->stores()[(int) $this->store]), 403);

        $data = $this->validate([
            'autoValidateTime' => ['nullable', 'date_format:H:i'],
            'reminderTime' => ['required', 'date_format:H:i'],
            'noReportTime' => ['required', 'date_format:H:i', 'after:reminderTime'],
            'columns' => ['array'],
            'columns.*' => [Rule::in(DailyReportSetting::COLUMNS)],
            'mode' => ['required', Rule::in(DailyReportSetting::MODES)],
            'recipients' => ['array', 'max:'.DailyReportSetting::MAX_RECIPIENTS],
            'recipients.*' => ['integer'],
        ]);

        $candidates = $this->candidates();
        $ids = array_values(array_unique(array_map('intval', $data['recipients'])));
        if (array_diff($ids, array_keys($candidates)) !== []) {
            throw ValidationException::withMessages(['recipients' => __('A chosen employee is not an active member of this store.')]);
        }

        $actions->saveSettings($this->user(), (int) $this->store, [
            'auto_send_on_validate' => $this->autoSend,
            'auto_validate_time' => ($data['autoValidateTime'] ?? '') === '' ? null : $data['autoValidateTime'],
            'reminder_time' => $data['reminderTime'],
            'no_report_time' => $data['noReportTime'],
            'show_columns' => array_values($data['columns']) === [] ? DailyReportSetting::COLUMNS : array_values($data['columns']),
            'cumulative_mode' => $data['mode'],
            'mail_recipient_ids' => $ids,
        ]);

        Flux::toast(variant: 'success', text: __('Settings saved.'));
    }

    public function render(): View
    {
        return app('view')->make($this->viewName('settings'), [
            'stores' => $this->stores(),
            'candidates' => $this->candidates(),
            'allColumns' => DailyReportSetting::COLUMNS,
            'modes' => DailyReportSetting::MODES,
        ]);
    }
}
