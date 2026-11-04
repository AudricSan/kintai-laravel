<x-layouts::app.sidebar :title="$title ?? null">
    <flux:main>
        <x-password-reminder />

        {{ $slot }}
    </flux:main>
</x-layouts::app.sidebar>
