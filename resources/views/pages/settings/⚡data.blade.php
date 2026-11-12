<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('My data')] class extends Component {
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('My data') }}</flux:heading>

    <x-pages::settings.layout :heading="__('My data')" :subheading="__('Download a copy of the personal data Kintai holds about you.')">
        <div class="space-y-4" data-test="personal-data">
            <flux:text size="sm">{{ __('The file contains your identity, HR details, store memberships, roles, shifts, availability and notification preferences. It never contains your password, security codes or access links.') }}</flux:text>
            <flux:button :href="route('data-export.download')" icon="arrow-down-tray" data-test="export-my-data">{{ __('Export my data') }}</flux:button>
        </div>
    </x-pages::settings.layout>
</section>
