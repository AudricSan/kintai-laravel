<?php

use App\Actions\Backups\CreateBackup;
use App\Actions\Backups\DeleteBackup;
use App\Actions\Backups\RestoreBackup;
use App\Actions\Backups\SaveBackupSettings;
use App\Backups\BackupException;
use App\Backups\BackupManager;
use App\Settings\AppSettings;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Backups')] class extends Component {
    public string $note = '';

    public bool $auto_enabled = true;

    public string $max_keep = '14';

    /** Sauvegarde visée par la modale ouverte (suppression ou restauration) : cherchée dans la liste à chaque action. */
    #[Locked]
    public string $pendingId = '';

    public string $password = '';

    /** Message d'une opération qui a échoué (création, suppression, restauration). */
    public string $error = '';

    public function mount(): void
    {
        abort_unless(Gate::allows('manage-settings'), 403);

        $settings = app(AppSettings::class);
        $this->auto_enabled = $settings->backupAutoEnabled();
        $this->max_keep = (string) $settings->backupMaxKeep();
    }

    public function create(CreateBackup $create): void
    {
        $this->resetErrorBag();
        $this->error = '';

        $this->validate(['note' => ['nullable', 'string', 'max:'.BackupManager::NOTE_MAX]], [], ['note' => __('Note')]);

        try {
            $create->handle(Auth::user(), $this->note);
        } catch (BackupException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->note = '';
        Flux::toast(variant: 'success', text: __('Backup created.'));
    }

    public function saveSettings(SaveBackupSettings $save): void
    {
        $this->resetErrorBag();

        $save->handle(Auth::user(), $this->auto_enabled, $this->max_keep);

        $this->max_keep = (string) app(AppSettings::class)->backupMaxKeep();
        Flux::toast(variant: 'success', text: __('Settings saved.'));
    }

    public function confirmDelete(string $id): void
    {
        $this->openModal($id, 'confirm-backup-deletion');
    }

    public function deletePending(DeleteBackup $delete): void
    {
        $this->error = '';

        try {
            $delete->handle(Auth::user(), $this->pendingId);
            Flux::toast(variant: 'success', text: __('Backup deleted.'));
        } catch (BackupException $e) {
            $this->error = $e->getMessage();
        }

        Flux::modal('confirm-backup-deletion')->close();
    }

    public function confirmDeleteAll(): void
    {
        Flux::modal('confirm-backups-deletion')->show();
    }

    public function deleteAll(DeleteBackup $delete): void
    {
        $deleted = $delete->all(Auth::user());

        Flux::modal('confirm-backups-deletion')->close();
        Flux::toast(variant: 'success', text: trans_choice(':count backup deleted.|:count backups deleted.', $deleted, ['count' => $deleted]));
    }

    public function confirmRestore(string $id): void
    {
        $this->password = '';
        $this->openModal($id, 'confirm-backup-restore');
    }

    public function restorePending(RestoreBackup $restore): void
    {
        $this->resetErrorBag();
        $this->error = '';

        try {
            $restore->handle(Auth::user(), $this->pendingId, $this->password);
        } catch (BackupException $e) {
            // Le mot de passe est correct (vérifié avant), l'opération a échoué : message et sauvegarde de sécurité.
            $this->password = '';
            $this->error = $e->getMessage();
            Flux::modal('confirm-backup-restore')->close();

            return;
        }

        // Comptes, sessions et rôles sont ceux de la sauvegarde : la session actuelle n'a plus de valeur, reconnexion.
        Auth::guard('web')->logout();
        session()->invalidate();
        session()->regenerateToken();
        session()->flash('status', __('The backup was restored. Sign in again to continue.'));

        $this->redirect(route('login'));
    }

    private function openModal(string $id, string $modal): void
    {
        $this->error = '';
        $this->pendingId = app(BackupManager::class)->find($id)?->id ?? '';

        if ($this->pendingId === '') {
            $this->error = __('This backup does not exist.');

            return;
        }

        Flux::modal($modal)->show();
    }

    public function with(): array
    {
        abort_unless(Gate::allows('manage-settings'), 403);

        $settings = app(AppSettings::class);
        $manager = app(BackupManager::class);

        $supported = true;
        try {
            $manager->dumper();
        } catch (BackupException) {
            $supported = false;
        }

        return [
            'backups' => $supported ? $manager->all() : [],
            'supported' => $supported,
            'lastSuccess' => $settings->backupLastSuccessAt(),
            'lastFailure' => $settings->backupLastFailure(),
            'pending' => $this->pendingId === '' ? null : $manager->find($this->pendingId),
            'timezone' => (string) config('kintai.timezone'),
        ];
    }
}; ?>

<section class="w-full space-y-8 p-4">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Backups') }}</flux:heading>
            <flux:subheading>{{ __('Complete copies of the database and of the uploaded files, kept on the server outside the public folder. Only the Owner can manage them.') }}</flux:subheading>
        </div>
        <flux:button :href="route('data-reset.index')" icon="exclamation-triangle" wire:navigate data-test="open-data-reset">{{ __('Reset data') }}</flux:button>
    </div>

    @unless ($supported)
        <flux:callout variant="warning" icon="exclamation-triangle" data-test="backups-unsupported">
            <flux:callout.text>{{ __('Backups are not available for this database type. SQLite, MySQL and MariaDB are supported.') }}</flux:callout.text>
        </flux:callout>
    @endunless

    @if ($error !== '')
        <flux:callout variant="danger" icon="exclamation-triangle" data-test="backup-error">
            <flux:callout.text>{{ $error }}</flux:callout.text>
        </flux:callout>
    @endif

    {{-- État de la sauvegarde automatique --}}
    <div class="max-w-2xl space-y-2" data-test="backup-status">
        @if ($lastFailure !== '')
            <flux:callout variant="danger" icon="exclamation-triangle" data-test="backup-last-failure">
                <flux:callout.heading>{{ __('The last automatic backup failed.') }}</flux:callout.heading>
                <flux:callout.text>{{ $lastFailure }}</flux:callout.text>
            </flux:callout>
        @endif
        <flux:text size="sm" data-test="backup-last-success">
            @if ($lastSuccess)
                {{ __('Last successful automatic backup: :date', ['date' => $lastSuccess->timezone($timezone)->format('Y-m-d H:i')]) }}
            @else
                {{ __('No automatic backup has succeeded yet.') }}
            @endif
        </flux:text>
    </div>

    {{-- Création --}}
    <form wire:submit="create" class="max-w-2xl space-y-3" data-test="section-create">
        <flux:heading size="lg">{{ __('New backup') }}</flux:heading>
        <flux:input wire:model="note" :label="__('Note (optional)')" maxlength="200" data-test="backup-note" />
        <flux:button variant="primary" type="submit" icon="archive-box-arrow-down" :disabled="! $supported" data-test="create-backup">{{ __('Create a backup') }}</flux:button>
    </form>

    {{-- Liste --}}
    <div class="space-y-3" data-test="section-list">
        <div class="flex items-center justify-between gap-4">
            <flux:heading size="lg">{{ __('Available backups') }}</flux:heading>
            @if (count($backups) > 0)
                <flux:button size="sm" variant="danger" wire:click="confirmDeleteAll" data-test="delete-all-backups">{{ __('Delete all') }}</flux:button>
            @endif
        </div>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Date') }}</flux:table.column>
                <flux:table.column>{{ __('Note') }}</flux:table.column>
                <flux:table.column>{{ __('Size') }}</flux:table.column>
                <flux:table.column>{{ __('Database') }}</flux:table.column>
                <flux:table.column>{{ __('Version') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($backups as $backup)
                    <flux:table.row :key="$backup->id" data-test="backup-row">
                        <flux:table.cell>{{ $backup->createdAt->timezone($timezone)->format('Y-m-d H:i') }}</flux:table.cell>
                        <flux:table.cell>
                            {{ $backup->note }}
                            @if ($backup->trigger === 'scheduled')
                                <flux:badge size="sm" inset="top bottom">{{ __('Automatic') }}</flux:badge>
                            @elseif ($backup->trigger === 'safety' || $backup->trigger === 'reset')
                                <flux:badge size="sm" color="amber" inset="top bottom">{{ __('Safety') }}</flux:badge>
                            @endif
                            @unless ($backup->valid)
                                <flux:badge size="sm" color="red" inset="top bottom">{{ __('Unreadable') }}</flux:badge>
                            @endunless
                        </flux:table.cell>
                        <flux:table.cell>{{ Number::fileSize($backup->size) }}</flux:table.cell>
                        <flux:table.cell>{{ $backup->driver }}</flux:table.cell>
                        <flux:table.cell>{{ $backup->version }}</flux:table.cell>
                        <flux:table.cell>
                            <div class="flex justify-end gap-2">
                                <flux:button size="sm" icon="arrow-down-tray" :href="route('backups.download', $backup->id)" data-test="download-backup">{{ __('Download') }}</flux:button>
                                @if ($backup->valid)
                                    <flux:button size="sm" wire:click="confirmRestore('{{ $backup->id }}')" data-test="restore-backup">{{ __('Restore') }}</flux:button>
                                @endif
                                <flux:button size="sm" variant="danger" wire:click="confirmDelete('{{ $backup->id }}')" data-test="delete-backup">{{ __('Delete') }}</flux:button>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="6" data-test="no-backups">{{ __('No backup yet.') }}</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>

    <flux:separator />

    {{-- Sauvegarde automatique --}}
    <form wire:submit="saveSettings" class="max-w-2xl space-y-4" data-test="section-settings">
        <div>
            <flux:heading size="lg">{{ __('Automatic backup') }}</flux:heading>
            <flux:subheading>{{ __('A backup is made every night at 03:00 by the scheduled task. The oldest ones are deleted beyond the number to keep.') }}</flux:subheading>
        </div>
        <flux:switch wire:model="auto_enabled" :label="__('Automatic backup enabled')" data-test="backup-auto-enabled" />
        <flux:input wire:model="max_keep" type="number" min="0" max="365" :label="__('Backups to keep')" :description="__('0 keeps every backup. Maximum 365.')" data-test="backup-max-keep" />
        <flux:button variant="primary" type="submit" data-test="save-backup-settings">{{ __('Save') }}</flux:button>
    </form>

    {{-- Modales --}}
    <flux:modal name="confirm-backup-deletion" class="max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete this backup?') }}</flux:heading>
                <flux:subheading>{{ __('The archive is deleted from the server. This cannot be undone.') }}</flux:subheading>
            </div>
            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="deletePending" data-test="confirm-delete-backup">{{ __('Delete') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="confirm-backups-deletion" class="max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete every backup?') }}</flux:heading>
                <flux:subheading>{{ __('All the archives are deleted from the server, including the safety backups. This cannot be undone.') }}</flux:subheading>
            </div>
            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="deleteAll" data-test="confirm-delete-all-backups">{{ __('Delete all') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="confirm-backup-restore" class="max-w-lg">
        <form wire:submit="restorePending" class="space-y-6">
            <div class="space-y-3">
                <flux:heading size="lg">{{ __('Restore this backup?') }}</flux:heading>
                @if ($pending)
                    <flux:text data-test="restore-target">{{ $pending->createdAt->timezone($timezone)->format('Y-m-d H:i') }} {{ $pending->note }}</flux:text>
                @endif
                <flux:text>{{ __('The database and the uploaded files are replaced by those of this backup: everything entered since then is lost (shifts, accounts, roles, settings).') }}</flux:text>
                <flux:text>{{ __('A safety backup of the current state is made first, and the application is put in maintenance mode during the operation.') }}</flux:text>
                <flux:text>{{ __('You will be signed out at the end and must sign in again with an account of the restored backup.') }}</flux:text>
            </div>
            <flux:input wire:model="password" type="password" :label="__('Your password')" autocomplete="current-password" viewable data-test="restore-password" />
            @error('password') <flux:text size="sm" class="text-red-600" data-test="restore-password-error">{{ $message }}</flux:text> @enderror
            @error('backup') <flux:text size="sm" class="text-red-600">{{ $message }}</flux:text> @enderror
            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="danger" type="submit" data-test="confirm-restore-backup">{{ __('Restore') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
