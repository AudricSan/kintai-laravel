<section class="w-full max-w-3xl space-y-6 p-4">
    <div>
        <flux:button variant="ghost" size="sm" icon="arrow-left" :href="route('team-directory.index')" wire:navigate>{{ __('Back to the team') }}</flux:button>
    </div>

    <div class="flex items-center gap-4">
        <flux:avatar size="xl" :src="$colleague->avatarUrl" :name="$colleague->name" :initials="$colleague->initials()" />
        <div>
            <flux:heading size="xl" data-test="colleague-name">{{ $colleague->name }}</flux:heading>
            <flux:text>{{ implode(' · ', $colleague->stores) }}</flux:text>
        </div>
    </div>

    @if ($colleague->hasContact())
        <div class="space-y-1" data-test="colleague-contact">
            <flux:heading size="sm">{{ __('Contact') }}</flux:heading>
            @if ($colleague->email !== null)
                <p><strong>{{ __('Email') }}</strong> : {{ $colleague->email }}</p>
            @endif
            @if ($colleague->phone !== null)
                <p><strong>{{ __('Phone') }}</strong> : {{ $colleague->phone }}</p>
            @endif
            @if ($colleague->mobilePhone !== null)
                <p><strong>{{ __('Mobile phone') }}</strong> : {{ $colleague->mobilePhone }}</p>
            @endif
        </div>
    @endif

    @foreach (['bio' => __('Bio'), 'skills' => __('Skills'), 'languages_spoken' => __('Languages spoken'), 'hobbies' => __('Hobbies')] as $field => $label)
        @if (filled($profile?->{$field}))
            <div class="space-y-1" data-test="colleague-{{ $field }}">
                <flux:heading size="sm">{{ $label }}</flux:heading>
                <p class="whitespace-pre-line">{{ $profile->{$field} }}</p>
            </div>
        @endif
    @endforeach

    @if (! $colleague->hasContact() && ($profile === null || $profile->isEmpty()))
        <flux:text data-test="empty-profile">{{ __('This colleague has not filled in their team profile yet.') }}</flux:text>
    @endif

    @if ($colleague->isSelf)
        <flux:button variant="filled" :href="route('team-directory.profile')" wire:navigate>{{ __('Edit my team profile') }}</flux:button>
    @endif
</section>
