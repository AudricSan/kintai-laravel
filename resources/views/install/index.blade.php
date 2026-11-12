<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('Install Kintai') }}</title>
    {{-- Page autonome : avant l'installation, ni base de données ni ressources compilées ne sont garanties. --}}
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body { margin: 0; padding: 2rem 1rem; font: 16px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; background: #f4f4f5; color: #18181b; }
        main { max-width: 38rem; margin: 0 auto; background: #fff; border: 1px solid #e4e4e7; border-radius: .75rem; padding: 1.5rem; }
        h1 { margin: 0 0 .25rem; font-size: 1.5rem; }
        h2 { margin: 1.5rem 0 .5rem; font-size: 1.1rem; }
        p { margin: .25rem 0 1rem; color: #52525b; }
        ul { list-style: none; margin: 0; padding: 0; }
        li { padding: .15rem 0; }
        .ok::before { content: "\2713\00a0"; color: #15803d; }
        .ko::before { content: "\2717\00a0"; color: #b91c1c; }
        .warn::before { content: "!\00a0"; color: #a16207; }
        label { display: block; margin: .75rem 0 .25rem; font-weight: 600; }
        input, select { width: 100%; padding: .5rem .625rem; border: 1px solid #a1a1aa; border-radius: .375rem; font: inherit; }
        .error { color: #b91c1c; margin: .25rem 0 0; font-size: .9rem; }
        .alert { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; padding: .75rem; border-radius: .5rem; margin: 1rem 0; }
        button { margin-top: 1.5rem; padding: .625rem 1.25rem; border: 0; border-radius: .375rem; background: #18181b; color: #fff; font: inherit; font-weight: 600; cursor: pointer; }
        button[disabled] { opacity: .5; cursor: not-allowed; }
        .hint { font-size: .875rem; color: #71717a; margin: .25rem 0 0; }
    </style>
</head>
<body>
<main>
    <h1>{{ __('Install Kintai') }}</h1>
    <p>{{ __('Check the requirements, choose the database and create the Owner account.') }}</p>

    <h2>{{ __('Requirements') }}</h2>
    <ul>
        @foreach ($checks as $check)
            <li class="{{ $check['ok'] ? 'ok' : ($check['blocking'] ? 'ko' : 'warn') }}">{{ $check['label'] }}</li>
        @endforeach
    </ul>

    @error('install')
        <div class="alert" role="alert">{{ $message }}</div>
    @enderror

    @if ($canInstall)
        <form method="POST" action="{{ route('install.store') }}">
            @csrf

            <h2>{{ __('Database') }}</h2>
            @if ($databaseConfigured)
                <p>{{ __('The .env file already configures a database: it will be used as is.') }}</p>
            @else
                <label for="db_driver">{{ __('Database type') }}</label>
                <select id="db_driver" name="db_driver" required>
                    @if (in_array('sqlite', $drivers, true))
                        <option value="sqlite" @selected(old('db_driver', 'sqlite') === 'sqlite')>{{ __('SQLite (a file, nothing else to set up)') }}</option>
                    @endif
                    @if (in_array('mysql', $drivers, true))
                        <option value="mysql" @selected(old('db_driver') === 'mysql')>{{ __('MySQL / MariaDB') }}</option>
                    @endif
                </select>
                @error('db_driver')<p class="error">{{ $message }}</p>@enderror

                <p class="hint">{{ __('The fields below are only used for MySQL / MariaDB.') }}</p>

                <label for="db_host">{{ __('Database host') }}</label>
                <input id="db_host" name="db_host" value="{{ old('db_host', 'localhost') }}" autocomplete="off">
                @error('db_host')<p class="error">{{ $message }}</p>@enderror

                <label for="db_port">{{ __('Database port') }}</label>
                <input id="db_port" name="db_port" type="number" min="1" max="65535" value="{{ old('db_port', 3306) }}">
                @error('db_port')<p class="error">{{ $message }}</p>@enderror

                <label for="db_database">{{ __('Database name') }}</label>
                <input id="db_database" name="db_database" value="{{ old('db_database') }}" autocomplete="off">
                @error('db_database')<p class="error">{{ $message }}</p>@enderror

                <label for="db_username">{{ __('Database user') }}</label>
                <input id="db_username" name="db_username" value="{{ old('db_username') }}" autocomplete="off">
                @error('db_username')<p class="error">{{ $message }}</p>@enderror

                <label for="db_password">{{ __('Database password') }}</label>
                <input id="db_password" name="db_password" type="password" autocomplete="off">
                @error('db_password')<p class="error">{{ $message }}</p>@enderror

                <label for="url">{{ __('Public URL of Kintai') }}</label>
                <input id="url" name="url" type="url" value="{{ old('url', $defaultUrl) }}">
                @error('url')<p class="error">{{ $message }}</p>@enderror
            @endif

            <h2>{{ __('Owner account') }}</h2>

            <label for="name">{{ __('Name') }}</label>
            <input id="name" name="name" value="{{ old('name') }}" required autocomplete="name">
            @error('name')<p class="error">{{ $message }}</p>@enderror

            <label for="email">{{ __('Email') }}</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="email">
            @error('email')<p class="error">{{ $message }}</p>@enderror

            <label for="password">{{ __('Password') }}</label>
            <input id="password" name="password" type="password" required autocomplete="new-password">
            @error('password')<p class="error">{{ $message }}</p>@enderror

            <label for="password_confirmation">{{ __('Confirm password') }}</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password">

            <button type="submit">{{ __('Install') }}</button>
        </form>
    @else
        <div class="alert" role="alert">{{ __('Fix the blocking requirements above, then reload this page.') }}</div>
    @endif
</main>
</body>
</html>
