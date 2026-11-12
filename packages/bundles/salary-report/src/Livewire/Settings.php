<?php

namespace Kintai\Bundles\SalaryReport\Livewire;

use Flux\Flux;
use Illuminate\Contracts\View\View;
use Kintai\Bundles\SalaryReport\Models\SalaryReportMember;
use Kintai\Bundles\SalaryReport\Models\SalaryReportSetting;
use Kintai\Bundles\SalaryReport\SalaryReportActions;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Retenues d'un magasin (`salary_reports.settings` sur le magasin) : activation, taux (assurance santé, pension, assurance
 * emploi, impôt à la source), taxe résidentielle mensuelle, et employés assujettis.
 */
#[Title('Salary report settings')]
class Settings extends Component
{
    use ResolvesViews;

    #[Url(except: '')]
    public string $store = '';

    public bool $enabled = false;

    public string $healthRate = '0';

    public string $pensionRate = '0';

    public string $employmentRate = '0';

    public string $incomeTaxRate = '0';

    public string $residentTax = '0';

    /** @var list<int|string> */
    public array $subjects = [];

    public function mount(): void
    {
        $stores = $this->stores();
        abort_if($stores === [], 403);

        $this->store = ctype_digit($this->store) && isset($stores[(int) $this->store]) ? $this->store : (string) array_key_first($stores);
        $this->load();
    }

    /**
     * @return array<int, string>
     */
    private function stores(): array
    {
        return $this->access()->storesWith($this->user(), 'salary_reports.settings');
    }

    public function updatedStore(): void
    {
        abort_unless(isset($this->stores()[(int) $this->store]), 403);

        $this->load();
    }

    private function load(): void
    {
        $setting = SalaryReportSetting::forStore((int) $this->store);

        $this->enabled = $setting->deductions_enabled;
        $this->healthRate = $this->plain((string) $setting->health_insurance_rate);
        $this->pensionRate = $this->plain((string) $setting->pension_rate);
        $this->employmentRate = $this->plain((string) $setting->employment_insurance_rate);
        $this->incomeTaxRate = $this->plain((string) $setting->income_tax_rate);
        $this->residentTax = $this->plain((string) $setting->resident_tax_monthly);
        $this->subjects = SalaryReportMember::query()->where('store_id', (int) $this->store)->where('subject_to_deductions', true)->pluck('user_id')->map(fn ($id): int => (int) $id)->all();
    }

    private function plain(string $amount): string
    {
        return str_contains($amount, '.') ? (rtrim(rtrim($amount, '0'), '.') ?: '0') : $amount;
    }

    public function save(SalaryReportActions $actions): void
    {
        abort_unless(isset($this->stores()[(int) $this->store]), 403);

        $data = $this->validate([
            'healthRate' => ['required', 'numeric', 'min:0', 'max:100'],
            'pensionRate' => ['required', 'numeric', 'min:0', 'max:100'],
            'employmentRate' => ['required', 'numeric', 'min:0', 'max:100'],
            'incomeTaxRate' => ['required', 'numeric', 'min:0', 'max:100'],
            'residentTax' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'subjects' => ['array'],
            'subjects.*' => ['integer'],
        ]);

        $storeId = (int) $this->store;
        // Les employés inactifs, qu'on ne propose plus, gardent leur réglage.
        $shown = array_keys($this->access()->members($storeId));
        $kept = SalaryReportMember::query()->where('store_id', $storeId)->where('subject_to_deductions', true)->whereNotIn('user_id', $shown)->pluck('user_id')->map(fn ($id): int => (int) $id)->all();
        $chosen = array_values(array_intersect(array_map('intval', $data['subjects']), $shown));

        $actions->saveSettings($this->user(), $storeId, [
            'deductions_enabled' => $this->enabled,
            'health_insurance_rate' => $data['healthRate'],
            'pension_rate' => $data['pensionRate'],
            'employment_insurance_rate' => $data['employmentRate'],
            'income_tax_rate' => $data['incomeTaxRate'],
            'resident_tax_monthly' => $data['residentTax'],
        ], [...$chosen, ...$kept]);

        Flux::toast(variant: 'success', text: __('Settings saved.'));
    }

    public function render(): View
    {
        return app('view')->make($this->viewName('settings'), [
            'stores' => $this->stores(),
            'members' => isset($this->stores()[(int) $this->store]) ? $this->access()->members((int) $this->store) : [],
        ]);
    }
}
