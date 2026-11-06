<?php

namespace Kintai\Bundles\PlanningCheck\Livewire;

use App\Models\StoreMembership;
use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Kintai\Bundles\PlanningCheck\Models\PlanningCheckSetting;
use Kintai\Bundles\PlanningCheck\PlanningCheckAccess;
use Kintai\Bundles\PlanningCheck\PlanningCheckActions;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Réglages de la validation du planning d'un magasin (`planning_check.settings` sur le magasin) : activation, début
 * d'attente, heure limite, jours concernés, destinataires du PDF, shifts ouverts, PDF d'essai envoyé à soi.
 */
#[Title('Schedule validation settings')]
class Settings extends Component
{
    use ResolvesViews;

    #[Url(except: '')]
    public string $store = '';

    public bool $enabled = false;

    public string $windowStart = '06:00';

    public string $deadline = '12:00';

    /** @var list<int|string> */
    public array $weekdays = [];

    /** @var list<int|string> */
    public array $recipients = [];

    public bool $includeOpen = false;

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
        return app(PlanningCheckAccess::class)->storesWith($this->user(), 'planning_check.settings');
    }

    public function updatedStore(): void
    {
        abort_unless(isset($this->stores()[(int) $this->store]), 403);

        $this->load();
    }

    private function load(): void
    {
        $setting = PlanningCheckSetting::forStore((int) $this->store);

        $this->enabled = $setting->enabled;
        $this->windowStart = $setting->window_start_time;
        $this->deadline = $setting->deadline_time;
        $this->weekdays = $setting->days();
        $this->recipients = $setting->recipientIds();
        $this->includeOpen = $setting->include_open_shifts;
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

    public function save(PlanningCheckActions $actions): void
    {
        abort_unless(isset($this->stores()[(int) $this->store]), 403);

        $data = $this->validate([
            'windowStart' => ['required', 'date_format:H:i', 'before:deadline'],
            'deadline' => ['required', 'date_format:H:i'],
            'weekdays' => ['array'],
            'weekdays.*' => ['integer', 'between:1,7'],
            'recipients' => ['array', 'max:'.PlanningCheckSetting::MAX_RECIPIENTS],
            'recipients.*' => ['integer'],
        ]);

        $days = array_values(array_unique(array_map('intval', $data['weekdays'])));
        if ($days === []) {
            throw ValidationException::withMessages(['weekdays' => __('Choose at least one day.')]);
        }

        $ids = array_values(array_unique(array_map('intval', $data['recipients'])));
        if (array_diff($ids, array_keys($this->candidates())) !== []) {
            throw ValidationException::withMessages(['recipients' => __('A chosen employee is not an active member of this store.')]);
        }

        if ($this->enabled && $ids === []) {
            throw ValidationException::withMessages(['recipients' => __('Tick at least one recipient to turn the validation on.')]);
        }

        sort($days);
        $actions->saveSettings($this->user(), (int) $this->store, [
            'enabled' => $this->enabled,
            'deadline_time' => $data['deadline'],
            'window_start_time' => $data['windowStart'],
            'weekdays' => $days,
            'mail_recipient_ids' => $ids,
            'include_open_shifts' => $this->includeOpen,
        ]);

        Flux::toast(variant: 'success', text: __('Settings saved.'));
    }

    public function sendTest(PlanningCheckActions $actions): void
    {
        $actions->sendTest($this->user(), (int) $this->store);

        Flux::toast(variant: 'success', text: __('Test PDF sent to your address.'));
    }

    public function render(): View
    {
        return app('view')->make($this->viewName('settings'), [
            'stores' => $this->stores(),
            'candidates' => $this->candidates(),
            'weekdayNames' => [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'],
        ]);
    }
}
