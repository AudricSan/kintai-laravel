@props(['disabled' => false])

{{-- Champs d'identité communs à la création et à l'édition ; les wire:model visent le composant Livewire parent. --}}
<div class="grid gap-4 sm:grid-cols-2">
    <flux:input wire:model="last_name" :label="__('Last name')" type="text" required autocomplete="off" :disabled="$disabled" />
    <flux:input wire:model="first_name" :label="__('First name')" type="text" required autocomplete="off" :disabled="$disabled" />
    <flux:input wire:model="furigana_last_name" :label="__('Last name (furigana)')" type="text" required autocomplete="off" :disabled="$disabled" />
    <flux:input wire:model="furigana_first_name" :label="__('First name (furigana)')" type="text" required autocomplete="off" :disabled="$disabled" />
</div>
<flux:input wire:model="name" :label="__('Display name')" :description="__('Left empty, it is built from the last and first names.')" type="text" autocomplete="off" :disabled="$disabled" />
<flux:input wire:model.live.blur="email" :label="__('Email')" type="email" autocomplete="off" :disabled="$disabled" />
<flux:input wire:model.live.blur="employee_code" :label="__('Employee code')" :description="__('Letters, digits, dashes and underscores. Used to sign in with the store code.')" type="text" autocomplete="off" :disabled="$disabled" />
<flux:input wire:model="color" :label="__('Color')" type="color" :disabled="$disabled" />
