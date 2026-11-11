<?php

use App\Actions\Updates\ChangeUpdateChannel;
use App\Activity\ActivityLogger;
use App\Settings\AppSettings;
use App\Update\UpdateApplier;
use App\Update\UpdateChannel;
use App\Update\UpdateChecker;
use App\Update\UpdateException;
use App\Update\UpdateState;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Updates')] class extends Component {
    public string $channel = 'release';

    /** Message d'une opération qui a échoué (recherche, préparation, annulation, retour arrière, application, fin). */
    public string $error = '';

    /** Message de succès venu d'une redirection (fin de la mise à jour). */
    public string $success = '';

    /** Nombre de fichiers de la mise à jour qui vient d'être préparée (connu seulement à ce moment-là, 0 sinon). */
    public int $stagedFiles = 0;

    public function mount(): void
    {
        abort_unless(Gate::allows('manage-settings'), 403);

        $this->channel = app(AppSettings::class)->updateChannel()->value;
        $this->error = (string) session('update_error', '');
        $this->success = (string) session('update_success', '');
    }

    public function updatedChannel(ChangeUpdateChannel $change): void
    {
        abort_unless(Gate::allows('manage-settings'), 403);

        $channel = UpdateChannel::tryFrom($this->channel);

        if ($channel === null) {
            $this->channel = app(AppSettings::class)->updateChannel()->value;

            return;
        }

        $change->handle(Auth::user(), $channel);
        Flux::toast(variant: 'success', text: __('Settings saved.'));
    }

    /** Interroge GitHub (le cache de 15 minutes est contourné et rafraîchi). */
    public function check(UpdateChecker $checker): void
    {
        abort_unless(Gate::allows('manage-settings'), 403);

        $this->error = '';
        $this->success = '';

        try {
            $checker->check(app(AppSettings::class)->updateChannel());
        } catch (UpdateException $e) {
            $this->error = $e->getMessage();
        }
    }

    /** Télécharge et contrôle la dernière release trouvée par la recherche (jamais une release venue du navigateur). */
    public function prepare(UpdateChecker $checker, UpdateApplier $applier): void
    {
        abort_unless(Gate::allows('manage-settings'), 403);

        $this->error = '';
        $this->success = '';

        $release = $checker->cached(app(AppSettings::class)->updateChannel())['release'] ?? null;

        if ($release === null || ! $checker->isNewer($release)) {
            $this->error = __('No update to prepare. Check for updates first.');

            return;
        }

        try {
            $this->stagedFiles = $applier->stage($release)['files'];
        } catch (UpdateException $e) {
            $this->error = $e->getMessage();

            return;
        }

        Flux::toast(variant: 'success', text: __('The update is ready to be applied.'));
    }

    public function cancel(UpdateApplier $applier, ActivityLogger $logger): void
    {
        abort_unless(Gate::allows('manage-settings'), 403);

        $this->error = '';
        $this->success = '';

        try {
            $state = $applier->state();
            $applier->cancel();
        } catch (UpdateException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $logger->record('update.cancelled', null, null, ['from' => $state->from, 'to' => $state->to], Auth::user());
        Flux::toast(variant: 'success', text: __('The prepared update was cancelled.'));
    }

    public function rollback(UpdateApplier $applier, ActivityLogger $logger): void
    {
        abort_unless(Gate::allows('manage-settings'), 403);

        $this->error = '';
        $this->success = '';

        try {
            $state = $applier->state();
            $applier->rollback();
            $after = $applier->state();
        } catch (UpdateException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $logger->record('update.rolled_back', null, null, ['from' => $state->from, 'to' => $state->to, 'complete' => $after->phase === UpdateState::ROLLED_BACK], Auth::user());

        if ($after->phase === UpdateState::ROLLED_BACK) {
            Flux::toast(variant: 'success', text: __('The update was rolled back.'));
        }
    }

    public function with(): array
    {
        abort_unless(Gate::allows('manage-settings'), 403);

        $checker = app(UpdateChecker::class);
        $channel = app(AppSettings::class)->updateChannel();
        $state = null;
        $stateError = '';

        try {
            $state = app(UpdateApplier::class)->state();
        } catch (UpdateException $e) {
            $stateError = $e->getMessage();
        }

        $cached = $checker->cached($channel);
        $release = $cached['release'] ?? null;

        return [
            'installed' => (string) config('kintai.version'),
            'checked' => $cached !== null,
            'release' => $release,
            'newer' => $checker->isNewer($release),
            'state' => $state,
            'stateError' => $stateError,
            'timezone' => (string) config('kintai.timezone'),
        ];
    }
}; ?>

<section class="w-full space-y-8 p-4">
    <div>
        <flux:heading size="xl">{{ __('Updates') }}</flux:heading>
        <flux:subheading>{{ __('Update Kintai from the releases published on GitHub. Only the Owner can do it.') }}</flux:subheading>
    </div>

    @if ($error !== '')
        <flux:callout variant="danger" icon="exclamation-triangle" data-test="update-error">
            <flux:callout.text>{{ $error }}</flux:callout.text>
        </flux:callout>
    @endif

    @if ($stateError !== '')
        <flux:callout variant="danger" icon="exclamation-triangle" data-test="update-state-error">
            <flux:callout.text>{{ $stateError }}</flux:callout.text>
        </flux:callout>
    @endif

    @if ($success !== '')
        <flux:callout variant="success" icon="check-circle" data-test="update-success">
            <flux:callout.text>{{ $success }}</flux:callout.text>
        </flux:callout>
    @endif

    {{-- Alerte : mise à jour inachevée --}}
    @if ($state && in_array($state->phase, [UpdateState::SWAPPED, UpdateState::FAILED], true))
        <div class="space-y-3" data-test="update-unfinished">
            <flux:callout variant="danger" icon="exclamation-triangle">
                <flux:callout.heading>{{ $state->phase === UpdateState::FAILED ? __('The rollback of the update is incomplete.') : __('An update is unfinished.') }}</flux:callout.heading>
                <flux:callout.text>{{ __('Update from :from to :to.', ['from' => (string) $state->from, 'to' => (string) $state->to]) }}</flux:callout.text>
                @foreach ($state->error as $message)
                    <flux:callout.text data-test="update-state-message">{{ $message }}</flux:callout.text>
                @endforeach
            </flux:callout>
            <div class="flex flex-wrap gap-2">
                @if ($state->phase === UpdateState::SWAPPED && $state->swapComplete)
                    <form method="POST" action="{{ route('updates.finish') }}">
                        @csrf
                        <flux:button type="submit" variant="primary" data-test="finish-update">{{ __('Finish the update') }}</flux:button>
                    </form>
                @endif
                <flux:button wire:click="rollback" wire:confirm="{{ __('Roll the update back? The files (and the database if the migrations had started) are put back as they were.') }}" variant="danger" data-test="rollback-update">{{ __('Cancel (roll back)') }}</flux:button>
            </div>
        </div>
    @elseif ($state && $state->phase === UpdateState::DONE)
        <flux:callout variant="success" icon="check-circle" data-test="update-done">
            <flux:callout.text>{{ __('Kintai was updated from :from to :to.', ['from' => (string) $state->from, 'to' => (string) $state->to]) }}</flux:callout.text>
            @foreach ($state->error as $message)
                <flux:callout.text data-test="update-state-message">{{ $message }}</flux:callout.text>
            @endforeach
        </flux:callout>
    @endif

    {{-- Version et canal --}}
    <div class="max-w-2xl space-y-4" data-test="section-version">
        <flux:text data-test="installed-version">{{ __('Installed version: :version', ['version' => $installed]) }}</flux:text>
        <flux:select wire:model.live="channel" :label="__('Update channel')" :description="__('Release: stable versions only. Beta: also beta and release candidates. Alpha: every version.')" data-test="update-channel">
            <flux:select.option value="release">{{ __('Release (stable)') }}</flux:select.option>
            <flux:select.option value="beta">{{ __('Beta') }}</flux:select.option>
            <flux:select.option value="alpha">{{ __('Alpha') }}</flux:select.option>
        </flux:select>
    </div>

    {{-- Recherche --}}
    <div class="max-w-2xl space-y-3" data-test="section-check">
        <flux:button wire:click="check" icon="arrow-path" data-test="check-update">{{ __('Check for updates') }}</flux:button>

        @if ($checked)
            @if ($release && $newer)
                <div class="space-y-2" data-test="update-available">
                    <flux:heading size="lg">{{ __('Version :version is available.', ['version' => (string) $release->version]) }}</flux:heading>
                    @if ($release->publishedAt)
                        <flux:text size="sm" data-test="release-date">{{ __('Published on :date', ['date' => $release->publishedAt->timezone($timezone)->format('Y-m-d')]) }}</flux:text>
                    @endif
                    @if ($release->notes !== '')
                        <pre class="max-h-80 overflow-auto whitespace-pre-wrap rounded border p-3 text-sm" data-test="release-notes">{{ $release->notes }}</pre>
                    @endif
                    @if (! $state || in_array($state->phase, [UpdateState::IDLE, UpdateState::DONE, UpdateState::ROLLED_BACK], true))
                        <flux:button wire:click="prepare" variant="primary" icon="arrow-down-tray" data-test="prepare-update">{{ __('Prepare') }}</flux:button>
                    @endif
                </div>
            @else
                <flux:text data-test="update-up-to-date">{{ __('Kintai is up to date.') }}</flux:text>
            @endif
        @endif
    </div>

    {{-- Mise à jour préparée --}}
    @if ($state && $state->phase === UpdateState::STAGED)
        <div class="max-w-2xl space-y-4" data-test="update-staged">
            <flux:heading size="lg">{{ __('Update ready to be applied') }}</flux:heading>
            <flux:text data-test="staged-summary">{{ __('From :from to :to.', ['from' => (string) $state->from, 'to' => (string) $state->to]) }}</flux:text>
            @if ($stagedFiles > 0)
                <flux:text data-test="staged-files">{{ trans_choice(':count file|:count files', $stagedFiles, ['count' => $stagedFiles]) }}</flux:text>
            @endif
            <flux:text>{{ __('A backup is made first and the application is put in maintenance mode during the update, which can take a few minutes.') }}</flux:text>

            <form method="POST" action="{{ route('updates.apply') }}" class="space-y-3">
                @csrf
                <flux:input name="password" type="password" :label="__('Your password')" autocomplete="current-password" viewable data-test="apply-password" />
                <div class="flex flex-wrap gap-2">
                    <flux:button type="submit" variant="primary" data-test="apply-update">{{ __('Apply') }}</flux:button>
                    <flux:button type="button" wire:click="cancel" variant="filled" data-test="cancel-update">{{ __('Cancel') }}</flux:button>
                </div>
            </form>
        </div>
    @endif
</section>
