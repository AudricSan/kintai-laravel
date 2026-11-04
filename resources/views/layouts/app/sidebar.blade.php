<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        @php
            $menu = app(\App\Navigation\NavigationMenu::class);
            $navigation = $menu->sections(auth()->user());
            $bottomBar = $menu->bottomBar(auth()->user());
        @endphp

        <flux:sidebar sticky collapsible="mobile" class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                @foreach ($navigation as $section)
                    <flux:sidebar.group :heading="$section['heading'] !== null ? __($section['heading']) : null" class="grid" data-test="nav-section-{{ $section['key'] }}">
                        @foreach ($section['items'] as $item)
                            <flux:sidebar.item :icon="$item->icon" :href="route($item->route)" :current="$item->isCurrent()" wire:navigate data-test="nav-item-{{ $item->key }}">
                                {{ __($item->label) }}
                            </flux:sidebar.item>
                        @endforeach
                    </flux:sidebar.group>
                @endforeach
            </flux:sidebar.nav>

            <flux:spacer />

            <div class="hidden lg:block">
                <livewire:notification-bell key="bell-desktop" position="top" align="start" />
            </div>

            <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            <livewire:notification-bell key="bell-mobile" />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :avatar="auth()->user()->avatarUrl()"
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar
                                    :src="auth()->user()->avatarUrl()"
                                    :name="auth()->user()->name"
                                    :initials="auth()->user()->initials()"
                                />

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            {{ __('Settings') }}
                        </flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                            data-test="logout-button"
                        >
                            {{ __('Log out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        <!-- Barre mobile du bas -->
        <div class="h-16 lg:hidden"></div>
        <nav class="fixed inset-x-0 bottom-0 z-30 flex border-t border-zinc-200 bg-white lg:hidden dark:border-zinc-700 dark:bg-zinc-900" aria-label="{{ __('Quick navigation') }}" data-test="bottom-nav">
            @foreach ($bottomBar as $item)
                <a
                    href="{{ route($item->route) }}"
                    wire:navigate
                    @class([
                        'flex flex-1 flex-col items-center gap-0.5 px-1 py-2 text-xs',
                        'font-semibold text-zinc-900 dark:text-white' => $item->isCurrent(),
                        'text-zinc-500 dark:text-zinc-400' => ! $item->isCurrent(),
                    ])
                    data-test="bottom-nav-{{ $item->key }}"
                >
                    <flux:icon :name="$item->icon" class="size-5" />
                    <span class="max-w-full truncate">{{ __($item->label) }}</span>
                </a>
            @endforeach
        </nav>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
