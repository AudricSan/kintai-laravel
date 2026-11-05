<?php

use App\Actions\Exports\DownloadExport;
use App\Exports\ExportAccess;
use App\Exports\ExportTooLargeException;
use App\Models\Store;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Exports')] class extends Component {
    public string $dataset = '';

    /** « me » = mes données ; « stores » = les magasins cochés (tous ceux qui sont permis si aucun n'est coché). */
    public string $target = 'stores';

    /** @var list<string> */
    public array $storeIds = [];

    /** @var list<string> */
    public array $userIds = [];

    public string $from = '';

    public string $to = '';

    public string $format = 'csv';

    public function mount(): void
    {
        $timezone = (string) config('kintai.timezone');
        $this->from = now($timezone)->startOfMonth()->toDateString();
        $this->to = now($timezone)->endOfMonth()->toDateString();

        $available = app(ExportAccess::class)->available(Auth::user());
        $this->dataset = (string) array_key_first($available);
        $this->resetChoices($available);
    }

    public function updatedDataset(): void
    {
        $this->resetErrorBag();
        $this->resetChoices(app(ExportAccess::class)->available(Auth::user()));
    }

    public function updatedTarget(): void
    {
        $this->resetErrorBag();
        $this->storeIds = [];
        $this->userIds = [];
    }

    public function updatedStoreIds(): void
    {
        // Les employés cochés doivent rester parmi ceux des magasins choisis.
        $visible = array_column(app(ExportAccess::class)->employees(Auth::user(), $this->effectiveStoreIds()), 'id');
        $this->userIds = array_values(array_filter($this->userIds, fn (string $id): bool => in_array((int) $id, $visible, true)));
    }

    /**
     * @param  array<string, array{dataset: \Kintai\Contracts\Exports\ExportDataset, stores: list<int>, self: bool}>  $available
     */
    private function resetChoices(array $available): void
    {
        $option = $available[$this->dataset] ?? null;

        $this->target = $option !== null && $option['stores'] === [] && $option['self'] ? 'me' : 'stores';
        $this->storeIds = [];
        $this->userIds = [];
        $this->format = $option === null ? 'csv' : $option['dataset']->formats()[0];
    }

    /**
     * @return list<int>
     */
    private function effectiveStoreIds(): array
    {
        $option = app(ExportAccess::class)->available(Auth::user())[$this->dataset] ?? null;
        if ($option === null || $this->target === 'me') {
            return [];
        }

        $chosen = array_values(array_intersect(array_map('intval', $this->storeIds), $option['stores']));

        return $chosen === [] ? $option['stores'] : $chosen;
    }

    /**
     * @return array<string, mixed>
     */
    private function parameters(bool $hasPeriod): array
    {
        return array_filter([
            'dataset' => $this->dataset,
            'format' => $this->format,
            'target' => $this->target,
            'stores' => array_map('intval', $this->storeIds),
            'users' => array_map('intval', $this->userIds),
            'from' => $hasPeriod ? $this->from : '',
            'to' => $hasPeriod ? $this->to : '',
        ], fn (mixed $value): bool => $value !== [] && $value !== '');
    }

    public function download(): void
    {
        $this->resetErrorBag();

        $access = app(ExportAccess::class);
        $dataset = $access->available(Auth::user())[$this->dataset]['dataset'] ?? null;

        if ($dataset === null) {
            $this->addError('dataset', __('You cannot export this data set.'));

            return;
        }

        try {
            $scope = $access->scope(
                Auth::user(),
                $dataset,
                $this->target === 'me',
                array_map('intval', $this->storeIds),
                array_map('intval', $this->userIds),
                $this->from,
                $this->to,
            );
            // Même contrôle que le téléchargement : le message s'affiche ici plutôt que sur une page vierge.
            app(DownloadExport::class)->check($dataset, $scope, $this->format);
        } catch (AuthorizationException) {
            $this->addError('dataset', __('You cannot export this data set.'));

            return;
        } catch (ValidationException $e) {
            foreach ($e->errors() as $key => $messages) {
                $this->addError($key, $messages[0]);
            }

            return;
        } catch (ExportTooLargeException $e) {
            $this->addError('export', $e->userMessage());

            return;
        }

        $this->redirect(route('exports.download', $this->parameters($dataset->hasPeriod())));
    }

    public function with(): array
    {
        $user = Auth::user();
        $access = app(ExportAccess::class);
        $available = $access->available($user);
        $option = $available[$this->dataset] ?? null;

        $stores = $option === null ? collect() : Store::query()->whereIn('id', $option['stores'])->orderBy('name')->get(['id', 'name']);

        return [
            'available' => $available,
            'option' => $option,
            'stores' => $stores,
            'employees' => $option !== null && $option['dataset']->perEmployee() && $this->target === 'stores'
                ? $access->employees($user, $this->effectiveStoreIds())
                : [],
        ];
    }
}; ?>

<section class="w-full max-w-3xl space-y-6 p-4">
    <div>
        <flux:heading size="xl">{{ __('Exports') }}</flux:heading>
        <flux:subheading>{{ __('Download your data and the data of your stores as a spreadsheet, a PDF or a calendar file.') }}</flux:subheading>
    </div>

    @if ($option === null)
        <flux:text data-test="no-export">{{ __('Nothing to export yet.') }}</flux:text>
    @else
        <form wire:submit="download" class="space-y-6" data-test="export-form">
            <flux:select wire:model.live="dataset" :label="__('Data set')" data-test="export-dataset">
                @foreach ($available as $key => $item)
                    <flux:select.option value="{{ $key }}">{{ __($item['dataset']->label()) }}</flux:select.option>
                @endforeach
            </flux:select>
            @error('dataset') <flux:text class="text-red-500" data-test="export-error">{{ $message }}</flux:text> @enderror

            @if ($option['self'] && $option['stores'] !== [])
                <flux:radio.group wire:model.live="target" :label="__('Whose data')" data-test="export-target">
                    <flux:radio value="me" :label="__('My data only')" />
                    <flux:radio value="stores" :label="__('The data of my stores')" />
                </flux:radio.group>
            @endif

            @if ($target === 'stores')
                <div class="space-y-2" data-test="export-stores">
                    <flux:heading size="sm">{{ __('Stores') }}</flux:heading>
                    <flux:text size="sm">{{ __('Leave empty to export all the stores you can access.') }}</flux:text>
                    @foreach ($stores as $store)
                        <flux:checkbox wire:model.live="storeIds" value="{{ $store->id }}" :label="$store->name" wire:key="export-store-{{ $store->id }}" />
                    @endforeach
                </div>

                @if ($option['dataset']->perEmployee())
                    <div class="space-y-2" data-test="export-employees">
                        <flux:heading size="sm">{{ __('Employees') }}</flux:heading>
                        <flux:text size="sm">{{ __('Leave empty to export all employees.') }}</flux:text>
                        <div class="grid max-h-56 gap-1 overflow-y-auto rounded-lg border border-zinc-200 p-3 dark:border-zinc-700 sm:grid-cols-2">
                            @foreach ($employees as $employee)
                                <flux:checkbox wire:model="userIds" value="{{ $employee['id'] }}" :label="$employee['name']" wire:key="export-employee-{{ $employee['id'] }}" />
                            @endforeach
                        </div>
                    </div>
                @endif
            @endif

            @if ($option['dataset']->hasPeriod())
                <div class="grid gap-4 sm:grid-cols-2" data-test="export-period">
                    <flux:input wire:model="from" type="date" :label="__('From')" data-test="export-from" />
                    <flux:input wire:model="to" type="date" :label="__('To')" data-test="export-to" />
                </div>
                @error('from') <flux:text class="text-red-500" data-test="export-period-error">{{ $message }}</flux:text> @enderror
            @endif

            <flux:select wire:model="format" :label="__('Format')" data-test="export-format">
                @foreach ($option['dataset']->formats() as $format)
                    <flux:select.option value="{{ $format }}">{{ ['csv' => __('CSV'), 'xlsx' => __('Excel'), 'pdf' => __('PDF'), 'ics' => __('Calendar (ICS)')][$format] ?? strtoupper($format) }}</flux:select.option>
                @endforeach
            </flux:select>
            @error('format') <flux:text class="text-red-500" data-test="export-format-error">{{ $message }}</flux:text> @enderror
            @error('export') <flux:text class="text-red-500" data-test="export-too-large">{{ $message }}</flux:text> @enderror

            <flux:button type="submit" variant="primary" icon="arrow-down-tray" data-test="export-submit">{{ __('Download') }}</flux:button>
        </form>
    @endif
</section>
