<?php

use App\Actions\Navigation\SaveNavigationPreferences;
use Kintai\Contracts\Navigation\NavigationItem;
use App\Navigation\NavigationMenu;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Navigation settings')] class extends Component {
    /** @var list<string> */
    public array $hidden = [];

    /** @var list<string> */
    public array $bottom = [];

    public function mount(): void
    {
        $this->load();
    }

    public function save(): void
    {
        $this->resetErrorBag();

        app(SaveNavigationPreferences::class)->handle(Auth::user(), $this->hidden, $this->bottom);
        $this->load();

        Flux::toast(variant: 'success', text: __('Navigation preferences saved.'));
    }

    /**
     * Revient à la barre mobile par défaut (les entrées masquées sont conservées).
     */
    public function useDefaultBar(): void
    {
        $this->resetErrorBag();

        app(SaveNavigationPreferences::class)->handle(Auth::user(), $this->hidden, []);
        $this->load();

        Flux::toast(variant: 'success', text: __('Navigation preferences saved.'));
    }

    private function load(): void
    {
        $menu = app(NavigationMenu::class);
        $user = Auth::user();

        $this->hidden = $menu->hiddenKeys($user);
        $this->bottom = array_map(fn (NavigationItem $item): string => $item->key, $menu->bottomBar($user));
    }

    public function with(): array
    {
        $menu = app(NavigationMenu::class);

        return [
            'hideable' => $menu->hideable(Auth::user()),
            'available' => $menu->available(Auth::user()),
            'min' => NavigationMenu::BOTTOM_MIN,
            'max' => NavigationMenu::BOTTOM_MAX,
        ];
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Navigation settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Navigation')" :subheading="__('Choose which menu entries you see and which ones sit in the mobile bar')">
        <form wire:submit="save" class="space-y-8">
            <flux:checkbox.group wire:model="hidden" :label="__('Hidden entries')" :description="__('Checked entries are removed from your menu.')" data-test="hidden-entries">
                @foreach ($hideable as $item)
                    <flux:checkbox :value="$item->key" :label="__($item->label)" wire:key="hide-{{ $item->key }}" />
                @endforeach
            </flux:checkbox.group>

            <div>
                <flux:checkbox.group wire:model="bottom" :label="__('Mobile bottom bar')" :description="__('Pick :min to :max entries.', ['min' => $min, 'max' => $max])" data-test="bottom-entries">
                    @foreach ($available as $item)
                        <flux:checkbox :value="$item->key" :label="__($item->label)" wire:key="bottom-{{ $item->key }}" />
                    @endforeach
                </flux:checkbox.group>
                <flux:error name="bottom" />
            </div>

            <div class="flex items-center gap-4">
                <flux:button variant="primary" type="submit" data-test="save-navigation">{{ __('Save') }}</flux:button>
                <flux:button type="button" wire:click="useDefaultBar" data-test="default-bar">{{ __('Default mobile bar') }}</flux:button>
            </div>
        </form>
    </x-pages::settings.layout>
</section>
