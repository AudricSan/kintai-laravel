<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />
        <meta name="robots" content="noindex">
        <title>{{ __('Update in progress') }} - {{ config('app.name', 'Laravel') }}</title>
        @vite(['resources/css/app.css'])
    </head>
    <body class="min-h-screen bg-white antialiased dark:bg-zinc-900">
        <main class="flex min-h-svh flex-col items-center justify-center gap-4 p-6 text-center" data-test="update-finishing">
            <h1 class="text-2xl font-semibold text-zinc-900 dark:text-white">{{ __('Update in progress...') }}</h1>
            <p class="max-w-md text-zinc-600 dark:text-zinc-300">{{ __('The database is being updated. Do not close this page, it can take a few minutes.') }}</p>
            <form id="finish-form" method="POST" action="{{ route('updates.finish') }}">
                @csrf
                <button type="submit" class="rounded border px-4 py-2 text-zinc-900 dark:text-white" data-test="update-finish-button">{{ __('Finish the update') }}</button>
            </form>
            <noscript>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('JavaScript is disabled: use the button above to finish the update.') }}</p>
            </noscript>
        </main>
        <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">document.getElementById('finish-form').submit();</script>
    </body>
</html>
