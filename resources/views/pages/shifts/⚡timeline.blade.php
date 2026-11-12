<?php

use App\Actions\Shifts\CreateShift;
use App\Actions\Shifts\MoveShift;
use App\Authorization\Authorizer;
use App\Exceptions\ShiftWarningsException;
use App\Models\Shift;
use App\Planning\TimelineBuilder;
use App\Planning\TimelineLayout;
use App\Planning\TimelineViewport;
use App\Support\Money;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Timeline')] class extends Component {
    /** Vide = premier magasin visible */
    public string $store_id = '';

    /** Premier jour affiché ("Y-m-d"), vide = aujourd'hui */
    public string $start = '';

    /** day | 3days | week */
    public string $view = 'week';

    // --- Création rapide ---

    public string $qc_date = '';

    /** Vide = shift ouvert */
    public string $qc_user_id = '';

    public string $qc_start = '09:00';

    public string $qc_end = '13:00';

    public string $qc_pause = '0';

    /** @var list<string> avertissements en attente de confirmation */
    public array $warnings = [];

    /**
     * Opération en attente de confirmation : `kind` = create | move, plus ses paramètres.
     *
     * @var array<string, mixed>
     */
    #[Locked]
    public array $pending = [];

    public function mount(): void
    {
        abort_unless(Gate::allows('shifts.view'), 403);

        $this->store_id = (string) request()->query('store_id', '');
        $this->start = (string) request()->query('start', '');
        $this->view = (string) request()->query('view', 'week');
    }

    public function previous(): void
    {
        $this->start = $this->viewport()->previousStart();
    }

    public function next(): void
    {
        $this->start = $this->viewport()->nextStart();
    }

    public function today(): void
    {
        $this->start = now()->toDateString();
    }

    public function setView(string $view): void
    {
        $viewport = $this->viewport();
        $this->view = in_array($view, TimelineViewport::VIEWS, true) ? $view : 'week';
        // Le mode « jour » et « 3 jours » repartent du premier jour affiché.
        $this->start = $viewport->firstDay->toDateString();
    }

    private function viewport(): TimelineViewport
    {
        return TimelineViewport::resolve(Auth::user(), $this->store_id, $this->start, $this->view);
    }

    // --- Création rapide ---

    /**
     * Clic sur une case horaire : ouvre la modale pré-remplie (minute arrondie à 15 min).
     */
    public function openQuickCreate(string $date, ?int $userId, int $minute): void
    {
        $store = $this->viewport()->store;
        abort_if($store === null || ! app(Authorizer::class)->allows(Auth::user(), 'shifts.create', $store), 403);

        $start = TimelineLayout::snap($minute);

        $this->resetErrorBag();
        $this->qc_date = $date;
        $this->qc_user_id = $userId === null ? '' : (string) $userId;
        $this->qc_start = TimelineLayout::format($start);
        $this->qc_end = TimelineLayout::format(min($start + 240, TimelineLayout::DAY_MINUTES));
        $this->qc_pause = '0';

        Flux::modal('quick-shift')->show();
    }

    public function quickCreate(): void
    {
        $this->resetErrorBag();
        $this->createQuick(false);
    }

    private function createQuick(bool $force): void
    {
        $store = $this->viewport()->store;
        abort_if($store === null, 404);

        try {
            app(CreateShift::class)->handle(Auth::user(), [
                'store_id' => $store->id,
                'user_id' => $this->qc_user_id,
                'shift_date' => $this->qc_date,
                'start_time' => $this->qc_start,
                'end_time' => $this->qc_end,
                'pause_minutes' => $this->qc_pause,
            ], $force);
        } catch (ShiftWarningsException $e) {
            $this->askConfirmation(['kind' => 'create'], $e->messages());
            Flux::modal('quick-shift')->close();

            return;
        } catch (ValidationException $e) {
            if (! $force) {
                throw $e;
            }

            $this->toastRefusal($e);
            $this->pending = [];

            return;
        } catch (AuthorizationException) {
            $this->toastForbidden();

            return;
        }

        $this->pending = [];
        Flux::modal('quick-shift')->close();
        Flux::modal('timeline-warnings')->close();
        Flux::toast(variant: 'success', text: __('Shift saved.'));
    }

    // --- Glisser-déposer ---

    /**
     * Déplace un shift du magasin affiché : jour, minute de début (arrondie à 15 min, durée conservée)
     * et employé (null = ligne des shifts ouverts).
     */
    public function moveShift(int $shiftId, string $date, int $minute, ?int $userId): void
    {
        $this->applyMove(['kind' => 'move', 'shiftId' => $shiftId, 'date' => $date, 'minute' => $minute, 'userId' => $userId], false);
    }

    /**
     * @param  array<string, mixed>  $move
     */
    private function applyMove(array $move, bool $force): void
    {
        $this->resetErrorBag();
        $store = $this->viewport()->store;
        abort_if($store === null, 404);

        $shift = Shift::query()->forStore($store)->find((int) $move['shiftId']);
        if ($shift === null) {
            Flux::toast(variant: 'danger', text: __('This shift no longer exists.'));

            return;
        }

        $start = TimelineLayout::snap((int) $move['minute']);

        try {
            app(MoveShift::class)->handle(Auth::user(), $shift, [
                'shift_date' => (string) $move['date'],
                'start_time' => TimelineLayout::format($start),
                'end_time' => TimelineLayout::format($start + $shift->durationMinutes),
                'user_id' => $move['userId'],
            ], $force);
        } catch (ShiftWarningsException $e) {
            $this->askConfirmation($move, $e->messages());

            return;
        } catch (ValidationException $e) {
            $this->pending = [];
            Flux::modal('timeline-warnings')->close();
            $this->toastRefusal($e);

            return;
        } catch (AuthorizationException) {
            $this->pending = [];
            Flux::modal('timeline-warnings')->close();
            $this->toastForbidden();

            return;
        }

        $this->pending = [];
        Flux::modal('timeline-warnings')->close();
        Flux::toast(variant: 'success', text: __('Shift moved.'));
    }

    // --- Confirmation des avertissements ---

    /**
     * @param  array<string, mixed>  $pending
     * @param  list<string>  $messages
     */
    private function askConfirmation(array $pending, array $messages): void
    {
        $this->pending = $pending;
        $this->warnings = $messages;
        Flux::modal('timeline-warnings')->show();
    }

    /**
     * « Enregistrer quand même » : rejoue l'opération en attente avec `force`.
     */
    public function confirmWarnings(): void
    {
        $pending = $this->pending;

        if (($pending['kind'] ?? null) === 'create') {
            $this->createQuick(true);
        } elseif (($pending['kind'] ?? null) === 'move') {
            $this->applyMove($pending, true);
        }
    }

    public function cancelWarnings(): void
    {
        $this->pending = [];
        $this->warnings = [];
        Flux::modal('timeline-warnings')->close();
    }

    private function toastRefusal(ValidationException $e): void
    {
        Flux::toast(variant: 'danger', text: (string) collect($e->errors())->flatten()->first());
    }

    private function toastForbidden(): void
    {
        Flux::toast(variant: 'danger', text: __('You are not allowed to do this.'));
    }

    public function with(): array
    {
        abort_unless(Gate::allows('shifts.view'), 403);

        $authorizer = app(Authorizer::class);
        $actor = Auth::user();
        $viewport = $this->viewport();
        $store = $viewport->store;

        $withCost = $store !== null && $authorizer->allows($actor, 'payroll.view', $store);
        $days = $store === null ? [] : app(TimelineBuilder::class)->build($store, $viewport->firstDay, $viewport->numDays, $withCost);

        // Totaux par employé sur la période affichée.
        $totals = [];
        foreach ($days as $day) {
            foreach ($day['rows'] as $row) {
                $key = $row['user']?->id ?? 0;
                $totals[$key] ??= ['name' => $row['user']->name ?? __('Open shifts'), 'net' => 0, 'cost' => $withCost ? 0.0 : null];
                $totals[$key]['net'] += $row['net'];
                $totals[$key]['cost'] = $withCost ? $totals[$key]['cost'] + (float) $row['cost'] : null;
            }
        }

        return [
            'viewport' => $viewport,
            'store' => $store,
            'days' => $days,
            'totals' => array_values(array_filter($totals, fn (array $total): bool => $total['net'] > 0)),
            'withCost' => $withCost,
            'canCreate' => $store !== null && $authorizer->allows($actor, 'shifts.create', $store),
            'canMove' => $store !== null && $authorizer->allows($actor, 'shifts.update', $store),
            'members' => $store === null ? collect() : $store->members()->wherePivot('is_active', true)->orderBy('users.name')->get(['users.id', 'users.name']),
            'today' => now()->toDateString(),
        ];
    }
}; ?>

<section class="w-full space-y-6 p-4">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <flux:heading size="xl">{{ __('Timeline') }}</flux:heading>
        <div class="flex flex-wrap gap-2">
            <flux:button :href="route('shifts.index', ['store_id' => $store?->id])" wire:navigate icon="list-bullet">{{ __('Shifts') }}</flux:button>
            <flux:button :href="route('shifts.calendar', ['store_id' => $store?->id])" wire:navigate icon="calendar-days">{{ __('Calendar') }}</flux:button>
            @if ($store)
                <flux:button :href="route('shifts.timeline.print', ['store_id' => $store->id, 'start' => $viewport->firstDay->toDateString(), 'view' => $viewport->view])" target="_blank" icon="printer" data-test="print-link">{{ __('Print') }}</flux:button>
            @endif
        </div>
    </div>

    <div class="flex flex-wrap items-end gap-4">
        <flux:select wire:model.live="store_id" :label="__('Store')" class="max-w-xs">
            @foreach ($viewport->stores as $option)
                <flux:select.option value="{{ $option->id }}">{{ $option->name }}</flux:select.option>
            @endforeach
        </flux:select>
        <div class="flex items-center gap-1">
            <flux:button wire:click="previous" icon="chevron-left" variant="ghost" :aria-label="__('Previous period')" data-test="previous-period" />
            <strong class="min-w-48 text-center" data-test="period-label">
                {{ $viewport->firstDay->translatedFormat('d M') }}@if ($viewport->numDays > 1) - {{ $viewport->lastDay()->translatedFormat('d M Y') }}@else {{ $viewport->firstDay->translatedFormat('Y') }}@endif
            </strong>
            <flux:button wire:click="next" icon="chevron-right" variant="ghost" :aria-label="__('Next period')" data-test="next-period" />
            <flux:button wire:click="today" size="sm">{{ __('Today') }}</flux:button>
        </div>
        <flux:button.group>
            @foreach (['day' => __('Day'), '3days' => __('3 days'), 'week' => __('Week')] as $mode => $label)
                <flux:button size="sm" wire:click="setView('{{ $mode }}')" :variant="$viewport->view === $mode ? 'primary' : 'filled'" data-test="view-{{ $mode }}">{{ $label }}</flux:button>
            @endforeach
        </flux:button.group>
    </div>

    @if ($store === null)
        <flux:text>{{ __('No store found.') }}</flux:text>
    @else
        @if ($canMove)
            <flux:text size="sm">{{ __('Drag a bar to move a shift (15-minute steps), or focus it and use the arrow keys. Click an empty slot to add a shift.') }}</flux:text>
        @endif

        <div
            class="space-y-6"
            x-data="{
                drag: null,
                start(e) {
                    if (e.button !== 0 || e.target.closest('a, button')) return;
                    const bar = e.currentTarget;
                    bar.setPointerCapture(e.pointerId);
                    this.drag = { bar, x: e.clientX, y: e.clientY, grab: e.clientX - bar.getBoundingClientRect().left, moved: false };
                },
                move(e) {
                    const d = this.drag;
                    if (!d) return;
                    const dx = e.clientX - d.x, dy = e.clientY - d.y;
                    if (!d.moved && Math.abs(dx) + Math.abs(dy) < 5) return;
                    d.moved = true;
                    d.bar.style.transform = 'translate(' + dx + 'px, ' + dy + 'px)';
                    d.bar.style.opacity = '0.75';
                    d.bar.style.zIndex = '30';
                },
                async end(e) {
                    const d = this.drag;
                    this.drag = null;
                    if (!d || !d.moved) return;
                    const track = document.elementsFromPoint(e.clientX, e.clientY).map((el) => el.closest('[data-track]')).find(Boolean);
                    d.bar.style.transform = '';
                    d.bar.style.opacity = '';
                    d.bar.style.zIndex = '';
                    if (!track || track.dataset.movable !== '1') return;
                    const rect = track.getBoundingClientRect();
                    const minute = Math.round((e.clientX - d.grab - rect.left) / rect.width * 1440);
                    await this.$wire.moveShift(Number(d.bar.dataset.id), track.dataset.date, minute, track.dataset.userId === '' ? null : Number(track.dataset.userId));
                },
                cancel() {
                    const d = this.drag;
                    this.drag = null;
                    if (d) { d.bar.style.transform = ''; d.bar.style.opacity = ''; d.bar.style.zIndex = ''; }
                },
                key(e) {
                    const bar = e.currentTarget;
                    const track = bar.closest('[data-track]');
                    let minute = Number(bar.dataset.minute);
                    let userId = track.dataset.userId;
                    if (e.key === 'ArrowLeft') minute -= 15;
                    else if (e.key === 'ArrowRight') minute += 15;
                    else if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
                        const tracks = Array.from(bar.closest('[data-day]').querySelectorAll('[data-track][data-movable=\'1\']'));
                        const target = tracks[tracks.indexOf(track) + (e.key === 'ArrowUp' ? -1 : 1)];
                        if (!target) return;
                        userId = target.dataset.userId;
                    } else return;
                    e.preventDefault();
                    this.$wire.moveShift(Number(bar.dataset.id), track.dataset.date, minute, userId === '' ? null : Number(userId));
                },
                quick(e) {
                    const track = e.currentTarget;
                    const rect = track.getBoundingClientRect();
                    const minute = Math.floor((e.clientX - rect.left) / rect.width * 96) * 15;
                    this.$wire.openQuickCreate(track.dataset.date, track.dataset.userId === '' ? null : Number(track.dataset.userId), minute);
                },
            }"
            data-test="timeline-board"
        >
            @foreach ($days as $day)
                @php($dateKey = $day['date']->toDateString())
                <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700 {{ $dateKey === $today ? 'ring-2 ring-zinc-400' : '' }}" data-day="{{ $dateKey }}" data-test="timeline-day" wire:key="day-{{ $dateKey }}">
                    <div class="min-w-[56rem]">
                        <div class="flex flex-wrap items-center gap-3 border-b border-zinc-200 bg-zinc-50 px-3 py-2 dark:border-zinc-700 dark:bg-zinc-800">
                            <strong>{{ $day['date']->translatedFormat('l d M') }}</strong>
                            <flux:text size="sm">{{ trans_choice(':count shift|:count shifts', $day['shifts'], ['count' => $day['shifts']]) }} - {{ __(':count staff', ['count' => $day['staff']]) }}</flux:text>
                            @if ($day['understaffed'])
                                <flux:badge color="red" size="sm" data-test="understaffed-day">{{ __('Understaffed') }} ({{ $day['staff'] }}/{{ $store->min_staff_per_day }})</flux:badge>
                            @endif
                            <flux:text size="sm" class="ms-auto">
                                {{ intdiv($day['net'], 60) }}h{{ str_pad((string) ($day['net'] % 60), 2, '0', STR_PAD_LEFT) }}@if ($withCost) - {{ Money::forStore($day['cost'] ?? 0, $store) }}@endif
                            </flux:text>
                        </div>

                        <div class="grid" style="grid-template-columns: 10rem 1fr 7rem;">
                            {{-- Axe des heures : effectif par heure, sous-effectif en rouge, heures de faible effectif en ambre --}}
                            <div></div>
                            <div class="flex">
                                @foreach ($day['hourly'] as $hour => $count)
                                    <div class="flex-1 border-s border-zinc-200 text-center text-[10px] leading-tight dark:border-zinc-700 {{ $day['understaffedHours'][$hour] ? 'bg-red-200 text-red-900' : ($day['lowHours'][$hour] ? 'bg-amber-100 text-amber-900' : '') }}" data-hour="{{ $hour }}" @if ($day['understaffedHours'][$hour]) data-understaffed @endif @if ($day['lowHours'][$hour]) data-low-staff @endif>
                                        <div>{{ str_pad((string) $hour, 2, '0', STR_PAD_LEFT) }}</div>
                                        <div class="opacity-70">{{ $count }}</div>
                                    </div>
                                @endforeach
                            </div>
                            <div class="px-2 text-end text-[10px] leading-tight">{{ __('Total') }}</div>

                            @foreach ($day['rows'] as $row)
                                @php($userId = $row['user']?->id)
                                @php($interactive = ! $row['inactive'])
                                <div class="flex items-center border-t border-zinc-200 px-3 text-sm dark:border-zinc-700" data-test="timeline-row">
                                    <span class="truncate">{{ $row['user']?->name ?? __('Open shifts') }}</span>
                                </div>
                                <div
                                    class="relative border-t border-zinc-200 dark:border-zinc-700"
                                    style="height: {{ $row['lanes'] * 28 + 4 }}px; {{ $canCreate && $interactive ? 'cursor: cell; ' : '' }}background-image: repeating-linear-gradient(to right, transparent 0, transparent calc(100% / 24 - 1px), rgb(113 113 122 / 0.25) calc(100% / 24 - 1px), rgb(113 113 122 / 0.25) calc(100% / 24));"
                                    @if ($canCreate && $interactive) @click.self="quick($event)" @endif
                                    data-track
                                    data-date="{{ $dateKey }}"
                                    data-user-id="{{ $userId }}"
                                    data-movable="{{ $canMove && $interactive ? '1' : '0' }}"
                                >
                                    @foreach ($row['bars'] as $bar)
                                        @php($label = $bar['start'].'-'.$bar['end'])
                                        <div
                                            class="absolute flex items-center overflow-hidden rounded px-1 text-xs text-white shadow {{ $canMove ? 'cursor-grab' : '' }}"
                                            style="left: {{ $bar['left'] }}%; width: {{ $bar['width'] }}%; top: {{ $bar['lane'] * 28 + 2 }}px; height: 24px; background-color: {{ $bar['color'] }}; touch-action: none;"
                                            data-id="{{ $bar['id'] }}"
                                            data-minute="{{ $bar['startMinute'] }}"
                                            data-test="timeline-bar"
                                            title="{{ $label }}{{ $bar['type'] ? ' - '.$bar['type'] : '' }} ({{ $bar['net'] }} min)"
                                            wire:key="bar-{{ $bar['id'] }}"
                                            @if ($canMove)
                                                tabindex="0"
                                                @pointerdown="start($event)"
                                                @pointermove="move($event)"
                                                @pointerup="end($event)"
                                                @pointercancel="cancel()"
                                                @keydown="key($event)"
                                            @endif
                                        >
                                            @if ($canMove)
                                                <a href="{{ route('shifts.edit', $bar['id']) }}" wire:navigate class="truncate" data-test="timeline-bar-link">{{ $label }}</a>
                                            @else
                                                <span class="truncate">{{ $label }}</span>
                                            @endif
                                            @if ($bar['cutEnd'])
                                                <span class="ms-auto" aria-hidden="true">&rarr;</span>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                                <div class="flex items-center justify-end border-t border-zinc-200 px-2 text-xs dark:border-zinc-700">
                                    @if ($row['net'] > 0)
                                        <span>{{ intdiv($row['net'], 60) }}h{{ str_pad((string) ($row['net'] % 60), 2, '0', STR_PAD_LEFT) }}@if ($withCost)<br>{{ Money::forStore($row['cost'] ?? 0, $store) }}@endif</span>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        @if ($totals !== [])
            <div class="space-y-2" data-test="period-totals">
                <flux:heading size="lg">{{ __('Totals for the period') }}</flux:heading>
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Employee') }}</flux:table.column>
                        <flux:table.column>{{ __('Paid hours') }}</flux:table.column>
                        @if ($withCost)
                            <flux:table.column>{{ __('Cost') }}</flux:table.column>
                        @endif
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($totals as $total)
                            <flux:table.row>
                                <flux:table.cell>{{ $total['name'] }}</flux:table.cell>
                                <flux:table.cell>{{ intdiv($total['net'], 60) }}h{{ str_pad((string) ($total['net'] % 60), 2, '0', STR_PAD_LEFT) }}</flux:table.cell>
                                @if ($withCost)
                                    <flux:table.cell>{{ Money::forStore($total['cost'] ?? 0, $store) }}</flux:table.cell>
                                @endif
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </div>
        @endif
    @endif

    <flux:modal name="quick-shift" class="max-w-lg">
        <form wire:submit="quickCreate" class="space-y-4">
            <flux:heading size="lg">{{ __('Quick shift') }}</flux:heading>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:select wire:model="qc_user_id" :label="__('Employee')">
                    <flux:select.option value="">{{ __('Open shift (no employee)') }}</flux:select.option>
                    @foreach ($members as $member)
                        <flux:select.option value="{{ $member->id }}">{{ $member->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="qc_date" :label="__('Date')" type="date" required />
                <flux:input wire:model="qc_start" :label="__('Starts at')" type="time" required />
                <flux:input wire:model="qc_end" :label="__('Ends at')" type="time" required />
                <flux:input wire:model="qc_pause" :label="__('Break (minutes)')" type="number" min="0" max="1440" />
            </div>
            @foreach (['store_id', 'user_id', 'shift_date', 'start_time', 'end_time', 'pause_minutes'] as $field)
                @error($field) <flux:text class="text-red-500" data-test="quick-error-{{ $field }}">{{ $message }}</flux:text> @enderror
            @endforeach
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="quick-save-button">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="timeline-warnings" class="max-w-lg">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Warnings') }}</flux:heading>
            <ul class="list-disc space-y-1 ps-5" data-test="shift-warnings">
                @foreach ($warnings as $warning)
                    <li>{{ $warning }}</li>
                @endforeach
            </ul>
            <div class="flex justify-end gap-2">
                <flux:button variant="filled" wire:click="cancelWarnings">{{ __('Cancel') }}</flux:button>
                <flux:button wire:click="confirmWarnings" variant="primary" data-test="save-anyway-button">{{ __('Save anyway') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</section>
