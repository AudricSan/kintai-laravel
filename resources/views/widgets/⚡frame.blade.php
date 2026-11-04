<?php

use App\Dashboard\DashboardWidgetRegistry;
use Livewire\Component;

/**
 * Enveloppe d'un widget : isole ses erreurs (une exception au rendu le remplace par un message discret et est
 * journalisée) et le rafraîchit à son intervalle en le remontant avec une nouvelle clé.
 */
new class extends Component {
    public string $widget = '';

    public int $tick = 0;

    public function refresh(): void
    {
        $this->tick++;
    }

    public function with(): array
    {
        return ['definition' => app(DashboardWidgetRegistry::class)->get($this->widget)];
    }
}; ?>

@php
    // Monté ici (et non dans with()) pour que le widget soit rattaché à cette enveloppe.
    $html = null;
    if ($definition !== null) {
        try {
            $html = \Livewire\Livewire::mount($definition->component, [], 'widget-'.$definition->key.'-'.$tick);
        } catch (\Throwable $e) {
            report($e);
        }
    }
@endphp

<div @if ($definition?->refresh) wire:poll.{{ $definition->refresh }}s="refresh" @endif data-test="widget-frame-{{ $widget }}">
    @if ($html !== null)
        {!! $html !!}
    @else
        <p class="rounded-xl border border-dashed border-zinc-200 p-3 text-xs text-zinc-400 dark:border-zinc-700" data-test="widget-unavailable">
            {{ __('This widget is unavailable.') }}
        </p>
    @endif
</div>
