<section class="w-full max-w-4xl space-y-6 p-4">
    <div class="flex items-center justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ $editing ? __('Edit the hiring report') : __('New hiring report') }}</flux:heading>
            @if ($store)
                <flux:subheading>{{ $store->name }}</flux:subheading>
            @endif
        </div>
        <flux:button variant="filled" :href="$editing ? route('hiring-report.show', $reportId) : route('hiring-report.index', ['store' => $storeId ?: null])" wire:navigate>{{ __('Back') }}</flux:button>
    </div>

    <form wire:submit="save" class="space-y-6" data-test="hiring-form">
        <div class="space-y-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:heading size="lg">{{ __('Identity') }}</flux:heading>
            <div class="grid gap-4 sm:grid-cols-2">
                @unless ($editing)
                    <flux:select wire:model.live="storeId" :label="__('Store')" data-test="store-select">
                        @foreach ($stores as $id => $name)
                            <flux:select.option :value="$id">{{ $name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endunless
                <flux:select wire:model="userId" :label="__('Employee account')" data-test="user-select">
                    <flux:select.option value="">{{ __('None') }}</flux:select.option>
                    @foreach ($members as $id => $name)
                        <flux:select.option :value="$id">{{ $name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="values.employee_number" :label="__('Employee number')" />
                <flux:input wire:model="values.employee_name" :label="__('Employee name')" />
                <flux:input wire:model="values.furigana_last_name" :label="__('Last name (furigana)')" />
                <flux:input wire:model="values.furigana_first_name" :label="__('First name (furigana)')" />
                <flux:select wire:model="values.gender" :label="__('Gender')">
                    <flux:select.option value="">—</flux:select.option>
                    <flux:select.option value="male">{{ __('Male') }}</flux:select.option>
                    <flux:select.option value="female">{{ __('Female') }}</flux:select.option>
                    <flux:select.option value="other">{{ __('Other') }}</flux:select.option>
                </flux:select>
                <flux:select wire:model="values.tax_classification" :label="__('Tax classification')">
                    <flux:select.option value="">—</flux:select.option>
                    <flux:select.option value="kou">{{ __('Kou') }}</flux:select.option>
                    <flux:select.option value="otsu">{{ __('Otsu') }}</flux:select.option>
                </flux:select>
                <flux:input wire:model="values.birth_date" type="date" :label="__('Birth date')" />
                <flux:input wire:model="values.education" :label="__('Education')" />
            </div>
        </div>

        <div class="space-y-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:heading size="lg">{{ __('Contact') }}</flux:heading>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="values.postal_code" :label="__('Postal code')" />
                <flux:input wire:model="values.phone" :label="__('Phone')" />
                <flux:input wire:model="values.mobile_phone" :label="__('Mobile phone')" />
                <flux:input wire:model="values.email" type="email" :label="__('Email')" />
            </div>
            <flux:textarea wire:model="values.address" :label="__('Address')" rows="2" />
        </div>

        <div class="space-y-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:heading size="lg">{{ __('Guarantor') }}</flux:heading>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="values.guarantor_name" :label="__('Guarantor name')" />
                <flux:input wire:model="values.guarantor_phone" :label="__('Guarantor phone')" />
            </div>
        </div>

        <div class="space-y-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:heading size="lg">{{ __('Employment') }}</flux:heading>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="values.store_name" :label="__('Store name')" />
                <flux:input wire:model="values.hired_by" :label="__('Hired by')" />
                <flux:input wire:model="values.hire_date" type="date" :label="__('Hire date')" />
            </div>
            <flux:textarea wire:model="values.notes" :label="__('Notes')" rows="3" />
        </div>

        <div class="flex justify-end gap-2">
            <flux:button type="submit" variant="primary" data-test="save-report-button">{{ __('Save') }}</flux:button>
        </div>
    </form>
</section>
