<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Team profile') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Team profile')" :subheading="__('What your colleagues see on your page of the team directory')">
        <form wire:submit="save" class="my-6 w-full space-y-6">
            <flux:switch wire:model="show_in_directory" :label="__('Visible in the team directory')"
                         :description="__('Colleagues of your stores can see your picture and your team profile.')" />

            <flux:textarea wire:model="bio" :label="__('Bio')" rows="4" />
            <flux:input wire:model="skills" :label="__('Skills')" type="text" />
            <flux:input wire:model="languages_spoken" :label="__('Languages spoken')" type="text" />
            <flux:input wire:model="hobbies" :label="__('Hobbies')" type="text" />

            <flux:text class="text-sm">{{ __('The email and phone numbers you share are chosen in your profile.') }}</flux:text>

            <div class="flex items-center justify-end">
                <flux:button variant="primary" type="submit" class="w-full" data-test="save-team-profile-button">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </x-pages::settings.layout>
</section>
