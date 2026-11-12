@props(['title'])

<div {{ $attributes->class('h-full rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900') }}>
    <flux:heading size="lg" class="mb-3">{{ $title }}</flux:heading>

    {{ $slot }}
</div>
