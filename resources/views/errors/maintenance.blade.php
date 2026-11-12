<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />
        <meta name="robots" content="noindex">
        <title>{{ __('Maintenance') }} - {{ config('app.name', 'Laravel') }}</title>
        @vite(['resources/css/app.css'])
    </head>
    <body class="min-h-screen bg-white antialiased dark:bg-zinc-900">
        <main class="flex min-h-svh flex-col items-center justify-center gap-4 p-6 text-center" data-test="maintenance-page">
            <h1 class="text-2xl font-semibold text-zinc-900 dark:text-white">{{ __('Maintenance') }}</h1>
            <p class="max-w-md whitespace-pre-line text-zinc-600 dark:text-zinc-300" data-test="maintenance-message">{{ $message }}</p>
            @if ($supportEmail !== '')
                <p class="text-sm text-zinc-500 dark:text-zinc-400">
                    {{ __('Contact') }} <a class="underline" href="mailto:{{ $supportEmail }}">{{ $supportEmail }}</a>
                </p>
            @endif
            <a class="text-sm underline text-zinc-500 dark:text-zinc-400" href="{{ route('login') }}">{{ __('Log in') }}</a>
        </main>
    </body>
</html>
