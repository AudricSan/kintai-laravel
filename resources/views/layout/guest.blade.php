<!DOCTYPE html>
<html lang="en" data-mascot="{{ mascot_active() }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Kintai' }} — Kintai</title>
    <link rel="icon" href="{!! $BASE_URL !!}/assets/img/favicon.ico" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="{!! $BASE_URL !!}/assets/img/favicon-32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="{!! $BASE_URL !!}/assets/img/favicon-16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="{!! $BASE_URL !!}/assets/img/apple-touch-icon.png">
    <link rel="stylesheet" href="{!! $BASE_URL !!}/assets/css/app.css?v={!! asset_version() !!}">
    @php // Couleurs du thème et styles dynamiques de la page (style_class()) : bloc à nonce, aucun attribut de style en ligne.
    $_themeCss = \App\View\InlineStyles::rootVariables($app_theme_color_style ?? ''); @endphp
    @if ($_themeCss !== '')<style nonce="{!! csp_nonce() !!}">{!! $_themeCss !!}</style>@endif
    {!! inline_styles() !!}</head>
<body class="guest-layout">
    <div class="guest-main">
        <div class="guest-container">
            <div class="guest-brand">
                <img src="{!! $BASE_URL !!}/assets/img/{!! mascot_path('login') !!}" alt="{!! __('mascot_alt') !!}" class="guest-brand__mascot">
                <h1>Kintai</h1>
                @if (!empty($app_subtitle))
                    <p class="guest-brand__company">{{ $app_subtitle }}</p>
                @else
                    <p>Shift Management</p>
                @endif
            </div>
            @if (!empty($app_login_notice))
                <div class="guest-notice">{{ $app_login_notice }}</div>
            @endif
            <div class="guest-card">
                @foreach (\App\SessionFlash::pull() as $_flash)
                    <div class="alert alert--{{ $_flash['type'] }} mb-sm">{{ $_flash['text'] }}</div>
                @endforeach
                @include('layout.partials._old-input')
                @yield('content')            </div>
        </div>
    </div>

    @include('layout.partials._footer')
    <script src="{!! $BASE_URL !!}/assets/js/modules/old-input.js?v={!! asset_version() !!}"></script>
    {!! inline_styles() !!}</body>
</html>
