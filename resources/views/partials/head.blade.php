<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>
    {{ filled($title ?? null) ? $title.' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}
</title>

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">

{{-- PWA : manifeste et notifications push (le script ne s'active que pour un utilisateur connecté). --}}
<link rel="manifest" href="{{ route('pwa.manifest') }}">
<meta name="theme-color" content="#18181b">
@auth
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="kintai-push"
        data-vapid-key="{{ app(\App\Push\VapidKeys::class)->publicKey() }}"
        data-sw-url="{{ route('pwa.sw', [], false) }}"
        data-scope="{{ rtrim(request()->getBasePath(), '/') }}/"
        data-subscribe-url="{{ route('push.subscriptions.store', [], false) }}"
        data-status-url="{{ route('push.status', [], false) }}"
        data-user-id="{{ auth()->id() }}">
@endauth

@fonts

@vite(['resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance
