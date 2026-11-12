<?php

use App\Actions\RemoveAvatar;
use App\Actions\StoreAvatar;
use App\Actions\UpdateUserProfile;
use App\Support\Locales;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Profile settings')] class extends Component {
    use WithFileUploads;

    // Prénom, nom, nom affiché et e-mail sont gérés par le gérant : affichés en lecture seule, sans propriété liée.
    public string $locale = '';

    public string $phone = '';

    public string $mobile_phone = '';

    public string $postal_code = '';

    public string $address = '';

    public bool $share_email = false;

    public bool $share_phone = false;

    public bool $share_mobile_phone = false;

    public $avatar = null;

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $user = Auth::user();
        $profile = $user->profile;

        $this->locale = $user->locale ?? '';
        $this->phone = $profile?->phone ?? '';
        $this->mobile_phone = $profile?->mobile_phone ?? '';
        $this->postal_code = $profile?->postal_code ?? '';
        $this->address = $profile?->address ?? '';
        $this->share_email = (bool) $user->share_email;
        $this->share_phone = (bool) $user->share_phone;
        $this->share_mobile_phone = (bool) $user->share_mobile_phone;
    }

    /**
     * Enregistre la langue, les coordonnées personnelles et les choix de partage de l'utilisateur connecté.
     */
    public function updateProfileInformation(UpdateUserProfile $updateUserProfile): void
    {
        $user = Auth::user();
        $previousLocale = $user->locale;

        $updateUserProfile->handle($user, $this->only([
            'locale', 'phone', 'mobile_phone', 'postal_code', 'address',
            'share_email', 'share_phone', 'share_mobile_phone',
        ]));

        Flux::toast(variant: 'success', text: __('Profile updated.'));

        // La langue s'applique à la requête suivante : on recharge la page pour la voir tout de suite.
        if ($user->locale !== $previousLocale) {
            $this->redirect(route('profile.edit'), navigate: true);
        }
    }

    public function saveAvatar(StoreAvatar $storeAvatar): void
    {
        $this->validate([
            'avatar' => ['required', 'file', 'mimes:jpeg,png,gif,webp', 'max:5120'],
        ]);

        $storeAvatar->handle(Auth::user(), $this->avatar->get());

        $this->reset('avatar');
        Flux::toast(variant: 'success', text: __('Avatar updated.'));
    }

    public function removeAvatar(RemoveAvatar $removeAvatar): void
    {
        $removeAvatar->handle(Auth::user());

        $this->reset('avatar');
        Flux::toast(variant: 'success', text: __('Avatar removed.'));
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Profile settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Profile')" :subheading="__('Your contact details, language and sharing choices')">
        @php($user = auth()->user())

        <div class="my-6 space-y-4" data-test="avatar-section">
            <flux:heading size="sm">{{ __('Avatar') }}</flux:heading>
            <div class="flex items-center gap-4">
                <flux:avatar size="xl" :src="$user->avatarUrl()" :name="$user->name" :initials="$user->initials()" />
                <div class="space-y-2">
                    <input type="file" wire:model="avatar" accept="image/jpeg,image/png,image/gif,image/webp" class="text-sm" data-test="avatar-input" />
                    @error('avatar') <flux:text class="text-red-500">{{ $message }}</flux:text> @enderror
                    <div class="flex gap-2">
                        @if ($avatar)
                            <flux:button size="sm" variant="primary" wire:click="saveAvatar" data-test="save-avatar-button">{{ __('Save avatar') }}</flux:button>
                        @endif
                        @if ($user->avatar_path)
                            <flux:button size="sm" variant="danger" wire:click="removeAvatar" data-test="remove-avatar-button">{{ __('Remove avatar') }}</flux:button>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <form wire:submit="updateProfileInformation" class="my-6 w-full space-y-6">
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input :label="__('First name')" :value="$user->first_name" readonly />
                <flux:input :label="__('Last name')" :value="$user->last_name" readonly />
            </div>
            <flux:input :label="__('Display name')" :value="$user->name" readonly />
            <flux:input :label="__('Email')" :value="$user->email" readonly />
            <flux:input :label="__('Employee code')" :value="$user->employee_code" readonly />
            <flux:text class="text-sm">{{ __('These details are managed by your manager.') }}</flux:text>

            <flux:select wire:model="locale" :label="__('Language')">
                <flux:select.option value="">{{ __('Instance default') }}</flux:select.option>
                @foreach (Locales::SUPPORTED as $code => $label)
                    <flux:select.option :value="$code">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="phone" :label="__('Phone')" type="tel" autocomplete="tel" />
                <flux:input wire:model="mobile_phone" :label="__('Mobile phone')" type="tel" autocomplete="tel" />
                <flux:input wire:model="postal_code" :label="__('Postal code')" type="text" autocomplete="postal-code" />
            </div>
            <flux:input wire:model="address" :label="__('Address')" type="text" autocomplete="street-address" />

            <div class="space-y-3">
                <flux:heading size="sm">{{ __('Shared with colleagues of your stores') }}</flux:heading>
                <flux:checkbox wire:model="share_email" :label="__('Email')" />
                <flux:checkbox wire:model="share_phone" :label="__('Phone')" />
                <flux:checkbox wire:model="share_mobile_phone" :label="__('Mobile phone')" />
            </div>

            <div class="flex items-center justify-end">
                <flux:button variant="primary" type="submit" class="w-full" data-test="update-profile-button">
                    {{ __('Save') }}
                </flux:button>
            </div>
        </form>

        <livewire:pages::settings.delete-user-form />
    </x-pages::settings.layout>
</section>
