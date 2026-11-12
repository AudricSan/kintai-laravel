<section class="w-full max-w-6xl space-y-6 p-4">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Schedule import') }}</flux:heading>
            <flux:subheading>{{ __('Import the shifts of an Excel schedule, check them, then confirm') }}</flux:subheading>
        </div>
        <div class="flex flex-wrap gap-2">
            @if (count($stores) > 1)
                <flux:select wire:model.live="store" class="max-w-xs" data-test="store-select">
                    @foreach ($stores as $id => $name)
                        <flux:select.option :value="$id">{{ $name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif
            <flux:button variant="filled" icon="cog-6-tooth" :href="route('planning-import.settings', ['store' => $store])" wire:navigate data-test="settings-link">{{ __('Settings') }}</flux:button>
        </div>
    </div>

    @if ($result !== null)
        <flux:callout variant="success" icon="check-circle" data-test="result">
            <flux:callout.text>{{ __(':created created, :replaced replaced, :skipped skipped, :removed removed.', $result) }}</flux:callout.text>
        </flux:callout>
    @endif

    @if ($rows === [])
        <form wire:submit="preview" class="space-y-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" data-test="upload-form">
            <flux:input type="file" wire:model="file" :label="__('Excel file')" accept=".xlsx,.xls,.xlsm,.csv,.ods" data-test="file-input" />
            <flux:text size="sm">{{ __('The year and the month are read from the file name when it contains them (for example 2026年11月.xlsx).') }}</flux:text>
            <flux:error name="file" />
            <flux:button variant="primary" type="submit" icon="arrow-up-tray" data-test="preview-button">{{ __('Preview') }}</flux:button>
        </form>
    @else
        <div class="space-y-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" data-test="preview">
            <flux:heading size="lg">{{ __('Preview') }}</flux:heading>
            <flux:text size="sm">{{ __('Untick the shifts you do not want. A shift that overlaps another shift of the employee replaces it; the shifts of the imported employees that the file no longer contains are deleted.') }}</flux:text>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-zinc-200 dark:border-zinc-700">
                            <th class="p-2">{{ __('Import') }}</th>
                            <th class="p-2">{{ __('Date') }}</th>
                            <th class="p-2">{{ __('Name in the file') }}</th>
                            <th class="p-2">{{ __('Time') }}</th>
                            <th class="p-2">{{ __('Employee') }}</th>
                            <th class="p-2">{{ __('Notes') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $i => $row)
                            <tr class="border-b border-zinc-100 dark:border-zinc-800" wire:key="row-{{ $i }}" data-test="row">
                                <td class="p-2"><flux:checkbox wire:model="rows.{{ $i }}.skip" :value="true" :label="__('Skip')" /></td>
                                <td class="p-2">{{ $row['date'] }}</td>
                                <td class="p-2">{{ $row['staff_name'] }}</td>
                                <td class="p-2">{{ $row['start_time'] }} - {{ $row['end_time'] }} ({{ $row['hours'] }} h)</td>
                                <td class="p-2">
                                    <div class="flex items-center gap-2">
                                        <flux:select wire:model.live="rows.{{ $i }}.user_id" class="min-w-40">
                                            <flux:select.option value="0">{{ __('Unknown employee') }}</flux:select.option>
                                            @foreach ($members as $id => $name)
                                                <flux:select.option :value="$id">{{ $name }}</flux:select.option>
                                            @endforeach
                                        </flux:select>
                                        @if ((int) $row['user_id'] === 0 && $canCreate)
                                            <flux:button size="sm" variant="filled" wire:click="startCreate(@js($row['staff_name']))" data-test="create-employee-button">{{ __('Create') }}</flux:button>
                                        @endif
                                    </div>
                                </td>
                                <td class="p-2 space-x-1">
                                    @if ($row['exists'])
                                        <flux:badge size="sm" color="zinc">{{ __('Already planned') }}</flux:badge>
                                    @endif
                                    @if ($row['overlap'])
                                        <flux:badge size="sm" color="amber">{{ __('Replaces an overlapping shift') }}</flux:badge>
                                    @endif
                                    @if ($row['duplicate_count'] > 0)
                                        <flux:badge size="sm" color="blue">{{ __(':count duplicates merged', ['count' => $row['duplicate_count']]) }}</flux:badge>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="flex flex-wrap justify-end gap-2">
                <flux:button variant="filled" wire:click="cancel" data-test="cancel-button">{{ __('Cancel') }}</flux:button>
                <flux:button variant="primary" icon="check" wire:click="confirm" data-test="confirm-button">{{ __('Confirm the import') }}</flux:button>
            </div>
        </div>
    @endif

    <flux:modal name="create-import-employee" class="max-w-lg">
        <form wire:submit="createEmployee" class="space-y-4" data-test="create-employee-form">
            <flux:heading size="lg">{{ __('Create the employee') }}</flux:heading>
            <flux:text size="sm">{{ __('The employee joins this store with the default role and is linked to every shift of this name.') }}</flux:text>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="newLastName" :label="__('Last name')" required />
                <flux:input wire:model="newFirstName" :label="__('First name')" required />
                <flux:input wire:model="newFuriganaLastName" :label="__('Last name (furigana)')" required />
                <flux:input wire:model="newFuriganaFirstName" :label="__('First name (furigana)')" required />
            </div>
            <flux:error name="newLastName" />
            @foreach (['last_name', 'first_name', 'furigana_last_name', 'furigana_first_name', 'store_id'] as $field)
                <flux:error :name="$field" />
            @endforeach
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="primary" type="submit" data-test="create-employee-submit">{{ __('Create') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
