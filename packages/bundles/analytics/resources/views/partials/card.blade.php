{{-- Carte de chiffre : $label, $value, $sub (facultatif), $tone (facultatif : green, amber, red). --}}
<div class="rounded-xl border border-zinc-200 p-3 dark:border-zinc-700" data-test="stat-card">
    <div class="text-2xl font-semibold {{ match ($tone ?? '') { 'green' => 'text-green-600', 'amber' => 'text-amber-500', 'red' => 'text-red-600', default => '' } }}">{{ $value }}</div>
    <flux:text size="sm">{{ $label }}</flux:text>
    @if (($sub ?? '') !== '')
        <flux:text size="xs" class="text-zinc-400">{{ $sub }}</flux:text>
    @endif
</div>
