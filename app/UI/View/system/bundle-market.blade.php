@php
use kintai\UI\Components\Badge;
use kintai\UI\Components\Button;

/**
 * @var array $entries Liste de bundles catalogués, voir BundleMarketController::market().
 * @var string|null $BASE_URL
 * @var string|null $error
 * @var string|null $success
 * @var string|null $uninstalled
 * @var string $bundleUpdateChannel
 * @var string|null $channelSaved
 */

$channelAction = route_url('admin.bundles.market.channel');
$channels = ['release' => __('update_channel_release'), 'beta' => __('update_channel_beta'), 'alpha' => __('update_channel_alpha')];

// Repère visuel par bundle, faute d'icône dédiée par slug — même recette que
// storeChipColor() dans staff/stores.php (couleur dérivée du slug, stable
// d'un chargement à l'autre) et le même composant .avatar-chip que les
// listes employés/magasins, juste en plus grand (--lg).
$bundleChipColor = function (string $seed): string {
    $hue = crc32($seed) % 360;
    return "hsl({$hue}, 62%, 52%)";
};
@endphp
<div class="page-header">
    <h2 class="page-header__title">{!! __('bundle_market') !!} <span class="page-count">({!! count($entries) !!})</span></h2>
</div>

@includeInScope('_partials._settings-tabs')
@includeInScope('system._bundle-tabs')

@php // Le slug vient de l'URL : n'afficher le message que pour un slug bien formé, jamais un texte libre. @endphp
@if ($success && preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', (string) $success) === 1)
    <div class="alert alert--success mb-sm">{{ __('bundle_market_install_success', ['slug' => $success]) }}        @if (($activation ?? null) === 'activated') {{ __('bundle_market_activated') }}@endif</div>
    @if (($activation ?? null) === 'quota_full')
        <div class="alert alert--warning mb-sm">{{ __('bundle_market_installed_inactive_quota') }}            <a href="{!! route_url('admin.bundles') !!}">{{ __('bundle_quota_overflow_action') }}</a></div>
    @endif
@endif
@if ($uninstalled)
    <div class="alert alert--success mb-sm">{{ __('bundle_market_uninstall_success', ['slug' => $uninstalled]) }}</div>
@endif
@if ($channelSaved)
    <div class="alert alert--success mb-sm">{{ __('bundle_market_channel_saved', ['channel' => $channels[$channelSaved] ?? $channelSaved]) }}</div>
@endif
@php // Seul le code « invalid » a son message ici ; le détail d'un échec (« failed ») est affiché par le layout
// depuis la session (kintai\Core\SessionFlash), jamais lu dans l'URL. @endphp
@if ($error === 'invalid')
    <div class="alert alert--danger mb-sm">{{ __('bundle_market_invalid_request') }}</div>
@endif

<div class="card card--mb">
    <div class="card-body">
        <p class="form-hint mb-sm">{!! __('bundle_market_hint') !!}</p>
        <p class="mb-0">
            {!! __('bundle_market_channel_label') !!}            <span class="btn-group">
                @foreach ($channels as $value => $label)
                    @php $needsConfirm = $value !== 'release' && $value !== $bundleUpdateChannel; @endphp
                    <form method="POST" action="{{ $channelAction }}" class="d-inline"{!! $needsConfirm ? " data-confirm=\"" . htmlspecialchars(__('update_channel_switch_confirm'), ENT_QUOTES) . "\"" : '' !!}>
                        {!! csrf_field() !!}                        <input type="hidden" name="channel" value="{{ $value }}">
                        {!! Button::make($label)->sm()->{$value === $bundleUpdateChannel ? 'primary' : 'outline'}()->submit()->disabled($value === $bundleUpdateChannel)->render() !!}                    </form>
                @endforeach
            </span>
        </p>
    </div>
</div>

@if (empty($entries))
    <div class="card"><div class="card-body"><p class="form-hint">{!! __('bundle_market_none_found') !!}</p></div></div>
@endif

<div class="bundle-market-grid">
    @php foreach ($entries as $entry):
        $initials = strtoupper(mb_substr(preg_replace('/[^\p{L}\p{N}]/u', '', $entry['name']) ?: $entry['slug'], 0, 2));
        $chipColor = $bundleChipColor($entry['slug']);
    @endphp
        <div class="card bundle-market-card">
            <div class="card-body">
                <div class="bundle-market-card__header">
                    <span class="avatar-chip avatar-chip--lg {!! style_class(['--chip-bg' => $chipColor]) !!}">{{ $initials }}</span>
                    <div class="bundle-market-card__heading">
                        <h3 class="card-title">{{ $entry['name'] }}</h3>
                        <div class="bundle-market-card__meta">
                            @if ($entry['official'])
                                {!! Badge::make(__('official'))->primary()->xs()->render() !!}                            @else
                                {!! Badge::make(__('bundle_third_party'))->warning()->xs()->render() !!}                            @endif
                            @if (!$entry['orphaned'])
                                <span class="bundle-market-card__registry">{{ $entry['registry_name'] }}</span>
                            @endif
                        </div>
                    </div>
                </div>

                <p class="text-sm bundle-market-card__desc">{{ $entry['description'] }}</p>

                @if ($entry['orphaned'])
                    <p class="form-hint text-danger">{!! __('bundle_market_orphaned_hint') !!}</p>
                @endif
                @if (!$entry['official'])
                    <p class="form-hint text-danger">{!! __('bundle_market_third_party_warning') !!}</p>
                @endif

                <div class="bundle-market-card__status">
                    @if ($entry['installed_version'] !== null)
                        {!! Badge::make(__('bundle_market_installed_version', ['version' => $entry['installed_version']]))->muted()->sm()->render() !!}                        @if ($entry['update_available'])
                            {!! Badge::make(__('bundle_market_update_available', ['version' => $entry['latest_version']]))->success()->sm()->render() !!}                        @endif
                    @else
                        {!! Badge::make(__('bundle_market_not_installed'))->muted()->sm()->render() !!}                    @endif
                </div>

                @php
                    $showInstallForm = !$entry['orphaned'] && ($entry['installed_version'] === null || $entry['update_available']);
                    $installFormId = 'bundle-install-' . htmlspecialchars($entry['slug']);
                @endphp
                <div class="bundle-market-card__actions">
                    @if ($showInstallForm)
                    <form id="{!! $installFormId !!}" method="POST" action="{!! $BASE_URL !!}/admin/bundles/market/install"
                          class="bundle-market-install-form" data-stream-url="{!! $BASE_URL !!}/admin/bundles/market/install/stream"
                          data-dry-run-url="{!! $BASE_URL !!}/admin/bundles/market/dry-run"
                          data-dry-run-ok-label="{{ __('bundle_market_dry_run_ok') }}"
                          data-generic-error-label="{{ __('bundle_market_generic_error') }}">
                        {!! csrf_field() !!}                        <input type="hidden" name="slug" value="{{ $entry['slug'] }}">
                        <input type="hidden" name="repository_url" value="{{ $entry['repository_url'] }}">
                        <input type="hidden" name="registry_url" value="{{ $entry['registry_url'] }}">

                        <div class="bundle-market-card__row">
                            @if (count($entry['versions']) > 1)
                                <div class="form-group">
                                    <label class="form-label">{!! __('bundle_market_version') !!}</label>
                                    <select name="version" class="form-control form-control-sm">
                                        @foreach ($entry['versions'] as $v)
                                            <option value="{{ $v }}">{{ $v }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @else
                                <input type="hidden" name="version" value="{{ $entry['latest_version'] ?? '' }}">
                            @endif

                            <button type="button" class="btn btn--ghost btn--sm" data-dry-run-btn>{!! __('bundle_market_test') !!}</button>
                        </div>

                        @if (!$entry['official'])
                            <label class="form-check">
                                <input type="checkbox" name="confirm_third_party" value="1" required>
                                {!! __('bundle_market_confirm_third_party') !!}                            </label>
                        @endif

                        <div class="bundle-market-progress hidden" data-progress>
                            <div class="progress-bar"><div class="progress-bar__fill" data-progress-fill></div></div>
                            <p class="text-sm mt-xs" data-progress-label></p>
                        </div>
                    </form>
                    @endif

                    @if ($showInstallForm || $entry['installed_version'] !== null)
                    <div class="bundle-market-card__bottom-row">
                        @if ($showInstallForm)
                            <button type="submit" form="{!! $installFormId !!}" class="btn btn--primary btn--sm">
                                {!! $entry['installed_version'] !== null ? __('bundle_market_update') : __('bundle_market_install') !!}                            </button>
                        @endif

                        @if ($entry['installed_version'] !== null)
                            <form method="POST" action="{!! $BASE_URL !!}/admin/bundles/market/uninstall" class="bundle-market-card__uninstall-form"
                                  data-confirm="{{ __('bundle_market_uninstall_confirm', ['name' => $entry['name']]) }}">
                                {!! csrf_field() !!}                                <input type="hidden" name="slug" value="{{ $entry['slug'] }}">
                                <button type="submit" class="btn btn--danger btn--sm">{!! __('bundle_market_uninstall') !!}</button>
                            </form>
                        @endif
                    </div>
                    @endif
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<script src="{!! $BASE_URL !!}/assets/js/modules/bundle-market.js?v={!! asset_version() !!}"></script>
