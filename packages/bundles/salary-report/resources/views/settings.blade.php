<section class="w-full max-w-3xl space-y-6 p-4">
    <div class="flex items-center justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Salary report settings') }}</flux:heading>
            <flux:subheading>{{ __('Deductions applied to the pay of a store and the employees they concern') }}</flux:subheading>
        </div>
        <flux:button variant="filled" :href="route('salary-report.index', ['store' => $store])" wire:navigate>{{ __('Back') }}</flux:button>
    </div>

    <form wire:submit="save" class="space-y-6 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" data-test="settings-form">
        @if (count($stores) > 1)
            <flux:select wire:model.live="store" :label="__('Store')">
                @foreach ($stores as $id => $name)
                    <flux:select.option :value="$id">{{ $name }}</flux:select.option>
                @endforeach
            </flux:select>
        @endif

        <flux:switch wire:model="enabled" :label="__('Apply deductions')" :description="__('Reports of this store propose deductions for the employees chosen below.')" />

        <div class="grid gap-4 sm:grid-cols-2">
            <flux:input wire:model="healthRate" type="number" step="0.01" min="0" max="100" :label="__('Health insurance rate (%)')" />
            <flux:input wire:model="pensionRate" type="number" step="0.01" min="0" max="100" :label="__('Pension rate (%)')" />
            <flux:input wire:model="employmentRate" type="number" step="0.01" min="0" max="100" :label="__('Employment insurance rate (%)')" />
            <flux:input wire:model="incomeTaxRate" type="number" step="0.01" min="0" max="100" :label="__('Income tax rate (%)')" />
            <flux:input wire:model="residentTax" type="number" step="0.01" min="0" :label="__('Resident tax (monthly amount)')" />
        </div>

        <div class="space-y-3">
            <flux:heading size="lg">{{ __('Employees subject to deductions') }}</flux:heading>
            <flux:checkbox.group wire:model="subjects" data-test="subjects">
                @foreach ($members as $id => $name)
                    <flux:checkbox :value="$id" :label="$name" />
                @endforeach
            </flux:checkbox.group>
            <flux:error name="subjects" />
        </div>

        <div class="flex justify-end">
            <flux:button variant="primary" type="submit" data-test="save-settings-button">{{ __('Save') }}</flux:button>
        </div>
    </form>

    <div class="space-y-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" data-test="custom-deductions">
        <div>
            <flux:heading size="lg">{{ __('Custom deductions') }}</flux:heading>
            <flux:subheading>{{ __('Extra deductions of this store, applied with the others to the employees subject to deductions (:max at most).', ['max' => $maxDeductions]) }}</flux:subheading>
        </div>

        @if ($deductions->isNotEmpty())
            <flux:table>
                <flux:table.rows>
                    @foreach ($deductions as $deduction)
                        <flux:table.row :key="$deduction->id" data-test="deduction-row">
                            <flux:table.cell>{{ $deduction->name }}</flux:table.cell>
                            <flux:table.cell>{{ $deduction->kind === 'percent' ? $deduction->value.' %' : __('Monthly amount').' '.$deduction->value }}</flux:table.cell>
                            <flux:table.cell><flux:switch :checked="$deduction->is_active" wire:click="toggleDeduction({{ $deduction->id }})" :label="__('Active')" /></flux:table.cell>
                            <flux:table.cell align="end">
                                <flux:button size="sm" variant="filled" wire:click="editDeduction({{ $deduction->id }})">{{ __('Edit') }}</flux:button>
                                <flux:button size="sm" variant="danger" wire:click="deleteDeduction({{ $deduction->id }})" wire:confirm="{{ __('Delete this deduction? Existing reports keep what was applied.') }}">{{ __('Delete') }}</flux:button>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif

        <form wire:submit="saveDeduction" class="grid gap-4 sm:grid-cols-3" data-test="deduction-form">
            <flux:input wire:model="deductionName" maxlength="100" :label="__('Deduction name')" />
            <flux:select wire:model="deductionKind" :label="__('Type')">
                <flux:select.option value="percent">{{ __('Percentage of gross pay') }}</flux:select.option>
                <flux:select.option value="fixed">{{ __('Monthly amount') }}</flux:select.option>
            </flux:select>
            <flux:input wire:model="deductionValue" type="number" step="0.01" min="0" :label="__('Value')" />
            <div class="flex gap-2 sm:col-span-3 sm:justify-end">
                @if ($deductionId !== 0)
                    <flux:button variant="filled" wire:click="cancelDeduction">{{ __('Cancel') }}</flux:button>
                @endif
                <flux:button variant="primary" type="submit" data-test="save-deduction-button">{{ $deductionId === 0 ? __('Add') : __('Save') }}</flux:button>
            </div>
        </form>
        <flux:error name="deductionName" />
        <flux:error name="deductionKind" />
        <flux:error name="deductionValue" />
    </div>
</section>
