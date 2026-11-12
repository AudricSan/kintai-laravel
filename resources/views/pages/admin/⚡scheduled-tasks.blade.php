<?php

use App\Actions\Cron\CreateCronToken;
use App\Actions\Cron\RevokeCronToken;
use App\Cron\ScheduledTasks;
use App\Models\CronToken;
use App\Settings\AppSettings;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Scheduled tasks')] class extends Component {
    public string $name = '';

    /** Jeton en clair, montré une seule fois juste après sa création (jamais relu depuis la base). */
    #[Locked]
    public string $newToken = '';

    /** Jeton visé par la modale de révocation. */
    #[Locked]
    public int $revokeId = 0;

    public function mount(): void
    {
        abort_unless(Gate::allows('manage-settings'), 403);
    }

    public function createToken(CreateCronToken $create): void
    {
        $this->resetErrorBag();

        $this->newToken = $create->handle(Auth::user(), $this->name);
        $this->name = '';
    }

    public function dismissToken(): void
    {
        $this->newToken = '';
    }

    public function confirmRevoke(int $id): void
    {
        $this->revokeId = CronToken::query()->whereKey($id)->value('id') ?? 0;

        if ($this->revokeId !== 0) {
            Flux::modal('confirm-cron-token-revocation')->show();
        }
    }

    public function revokePending(RevokeCronToken $revoke): void
    {
        if ($this->revokeId !== 0 && $revoke->handle(Auth::user(), $this->revokeId)) {
            Flux::toast(variant: 'success', text: __('Token revoked.'));
        }

        $this->revokeId = 0;
        Flux::modal('confirm-cron-token-revocation')->close();
    }

    public function with(): array
    {
        abort_unless(Gate::allows('manage-settings'), 403);

        $publicUrl = app(AppSettings::class)->publicUrl();
        $base = $publicUrl !== '' ? $publicUrl : rtrim(request()->root(), '/');

        return [
            'tasks' => app(ScheduledTasks::class)->all(),
            'tokens' => CronToken::query()->orderBy('name')->orderBy('id')->get(),
            'endpoint' => $base.'/cron/run',
            'artisan' => 'php '.base_path('artisan').' schedule:run',
            'timezone' => (string) config('kintai.timezone'),
        ];
    }
}; ?>

<section class="w-full space-y-8 p-4">
    <div>
        <flux:heading size="xl">{{ __('Scheduled tasks') }}</flux:heading>
        <flux:subheading>{{ __('Purges, automatic backups, system alerts and documentation updates run on their own schedule. The server must start the scheduler every minute, with a cron job or with the web address below.') }}</flux:subheading>
    </div>

    {{-- Tâches --}}
    <div class="space-y-3" data-test="section-tasks">
        <flux:heading size="lg">{{ __('Tasks') }}</flux:heading>
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Task') }}</flux:table.column>
                <flux:table.column>{{ __('Frequency') }}</flux:table.column>
                <flux:table.column>{{ __('Next run') }}</flux:table.column>
                <flux:table.column>{{ __('Last run') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($tasks as $task)
                    <flux:table.row data-test="task-row">
                        <flux:table.cell><code>{{ $task['name'] }}</code></flux:table.cell>
                        <flux:table.cell><code>{{ $task['expression'] }}</code></flux:table.cell>
                        <flux:table.cell>{{ $task['next_due']?->timezone($timezone)->format('Y-m-d H:i') ?? '-' }}</flux:table.cell>
                        <flux:table.cell>
                            @php($failedLast = $task['last_failure_at'] !== null && ($task['last_success_at'] === null || $task['last_failure_at']->greaterThan($task['last_success_at'])))
                            @if ($failedLast)
                                <flux:badge size="sm" color="red" inset="top bottom" data-test="task-failed">{{ __('Failed') }}</flux:badge>
                                {{ $task['last_failure_at']->timezone($timezone)->format('Y-m-d H:i') }}
                                @if ($task['last_error'])
                                    <flux:text size="sm" class="text-red-600">{{ $task['last_error'] }}</flux:text>
                                @endif
                            @elseif ($task['last_success_at'] !== null)
                                <flux:badge size="sm" color="green" inset="top bottom" data-test="task-succeeded">{{ __('Succeeded') }}</flux:badge>
                                {{ $task['last_success_at']->timezone($timezone)->format('Y-m-d H:i') }}
                            @else
                                <flux:text size="sm" data-test="task-never">{{ __('Never run') }}</flux:text>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="4">{{ __('No scheduled task.') }}</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>

    <flux:separator />

    {{-- Mise en place --}}
    <div class="max-w-3xl space-y-4" data-test="section-setup">
        <flux:heading size="lg">{{ __('Start the scheduler') }}</flux:heading>

        <div class="space-y-2">
            <flux:heading>{{ __('With a terminal: a cron job every minute') }}</flux:heading>
            <flux:text size="sm">{{ __('Standard option on a server where you can edit the crontab:') }}</flux:text>
            <pre class="overflow-x-auto rounded-lg bg-zinc-100 p-3 text-sm dark:bg-zinc-800" data-test="cron-artisan-example">* * * * * {{ $artisan }} >> /dev/null 2>&1</pre>
        </div>

        <div class="space-y-2">
            <flux:heading>{{ __('Without a terminal: the web address') }}</flux:heading>
            <flux:text size="sm">{{ __('On shared hosting, or with an online cron service, call this address every minute. The token goes in the Authorization header (preferred) or in the token parameter.') }}</flux:text>
            <pre class="overflow-x-auto rounded-lg bg-zinc-100 p-3 text-sm dark:bg-zinc-800" data-test="cron-endpoint">{{ $endpoint }}</pre>
            <pre class="overflow-x-auto rounded-lg bg-zinc-100 p-3 text-sm dark:bg-zinc-800" data-test="cron-curl-example">* * * * * curl -fsS -H "Authorization: Bearer {{ $newToken !== '' ? $newToken : 'YOUR_TOKEN' }}" {{ $endpoint }} >/dev/null</pre>
            <flux:text size="sm">{{ __('For an online cron service that only accepts an address, use:') }}</flux:text>
            <pre class="overflow-x-auto rounded-lg bg-zinc-100 p-3 text-sm dark:bg-zinc-800" data-test="cron-url-example">{{ $endpoint }}?token={{ $newToken !== '' ? $newToken : 'YOUR_TOKEN' }}</pre>
            <flux:text size="sm">{{ __('An address with the token in it ends up in the access logs of the server: prefer the header when the service allows it.') }}</flux:text>
        </div>
    </div>

    <flux:separator />

    {{-- Jetons --}}
    <div class="max-w-3xl space-y-4" data-test="section-tokens">
        <flux:heading size="lg">{{ __('Tokens') }}</flux:heading>

        @if ($newToken !== '')
            <flux:callout variant="success" icon="key" data-test="new-token">
                <flux:callout.heading>{{ __('Copy this token now: it will not be shown again.') }}</flux:callout.heading>
                <flux:callout.text>
                    <code class="break-all" data-test="new-token-value">{{ $newToken }}</code>
                </flux:callout.text>
                <x-slot name="actions">
                    <flux:button size="sm" wire:click="dismissToken" data-test="dismiss-token">{{ __('I have copied it') }}</flux:button>
                </x-slot>
            </flux:callout>
        @endif

        <form wire:submit="createToken" class="flex flex-wrap items-end gap-3">
            <div class="min-w-64 flex-1">
                <flux:input wire:model="name" :label="__('Token name')" :placeholder="__('Online cron service')" maxlength="100" data-test="cron-token-name" />
            </div>
            <flux:button variant="primary" type="submit" icon="key" data-test="create-cron-token">{{ __('Create a token') }}</flux:button>
        </form>
        @error('name') <flux:text size="sm" class="text-red-600" data-test="cron-token-name-error">{{ $message }}</flux:text> @enderror

        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Name') }}</flux:table.column>
                <flux:table.column>{{ __('Created') }}</flux:table.column>
                <flux:table.column>{{ __('Last used') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($tokens as $token)
                    <flux:table.row :key="$token->id" data-test="token-row">
                        <flux:table.cell>{{ $token->name }}</flux:table.cell>
                        <flux:table.cell>{{ $token->created_at?->timezone($timezone)->format('Y-m-d H:i') }}</flux:table.cell>
                        <flux:table.cell>{{ $token->last_used_at?->timezone($timezone)->format('Y-m-d H:i') ?? __('Never') }}</flux:table.cell>
                        <flux:table.cell>
                            <div class="flex justify-end">
                                <flux:button size="sm" variant="danger" wire:click="confirmRevoke({{ $token->id }})" data-test="revoke-cron-token">{{ __('Revoke') }}</flux:button>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="4" data-test="no-tokens">{{ __('No token yet.') }}</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>

    <flux:modal name="confirm-cron-token-revocation" class="max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Revoke this token?') }}</flux:heading>
                <flux:subheading>{{ __('Whatever calls the address with this token stops working right away.') }}</flux:subheading>
            </div>
            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="revokePending" data-test="confirm-revoke-cron-token">{{ __('Revoke') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</section>
