@php
use App\View\Components\Button;
use App\View\Components\Card;

/**
 * Étape « Bundles » : un questionnaire en langage métier recommande des bundles, puis une sélection guidée (cartes
 * regroupées par thème, recherche, prérequis, quota) permet d'ajuster à la main avant d'installer.
 *
 * @var list<array<string, mixed>> $bundles      catalogue
 * @var int|null                   $maxBundles   quota du plan (null = illimité)
 * @var int|null                   $room         place restante dans le quota
 * @var list<array<string, mixed>> $questions    questions posées
 * @var array<string, array<string, string>> $cards pictogramme, thème et bénéfice par slug
 * @var list<string>               $pending      bundles déjà en attente de licence
 * @var string                     $mode         questions | select
 * @var list<string>               $recommended  slugs cochés d'avance
 */
$mode ??= 'questions';
$questions ??= [];
$cards ??= [];
$pending ??= [];
$recommended ??= [];
$room ??= null;

// Ordre de priorité : bundles des questions (dans l'ordre), puis les autres du catalogue.
$_bySlug = [];
foreach ($bundles as $_b) {
    $_bySlug[(string) $_b['slug']] = $_b;
}
$_ordered = [];
foreach ($questions as $_q) {
    foreach ($_q['bundles'] as $_slug) {
        if (isset($_bySlug[$_slug])) {
            $_ordered[$_slug] = $_bySlug[$_slug];
        }
    }
}
foreach ($_bySlug as $_slug => $_b) {
    $_ordered[$_slug] ??= $_b;
}
$_themes = [];
foreach ($_ordered as $_slug => $_b) {
    $_themes[$cards[$_slug]['theme'] ?? 'other'][$_slug] = $_b;
}
ob_start();
@endphp
@if ($pending !== [])
    <div class="alert alert--info mb-sm">{{ __('setup_pending_waiting', ['list' => implode(', ', $pending)]) }}</div>
@endif

@if (empty($bundles))
    <div class="alert alert--warning mb-sm">{!! __('setup_bundles_catalog_unavailable') !!}</div>

@elseif ($mode === 'questions' && $questions !== [])
    <p class="mb-sm">{!! __('setup_q_intro') !!}</p>
    <form method="POST" action="{!! route_url('admin.setup.questions') !!}" class="setup-questions">
        {!! csrf_field() !!}        @foreach ($questions as $_q)
            <label class="setup-question">
                <input type="checkbox" name="answers[]" value="{{ $_q['id'] }}">
                <span class="setup-question__icon" aria-hidden="true">{{ $_q['icon'] }}</span>
                <span class="setup-question__body">
                    <strong>{{ $_q['text'] }}</strong>
                    <span class="text-muted text-sm">{{ $_q['hint'] }}</span>
                </span>
            </label>
        @endforeach
        <div class="form-actions">
            {!! Button::make(__('setup_q_see_selection'))->primary()->submit()->render() !!}        </div>
    </form>
    <p class="text-sm mt-sm"><a href="{!! route_url('admin.setup') !!}?step=bundles&amp;mode=list">{!! __('setup_q_manual') !!}</a></p>

@else
    <p class="mb-sm">{!! $room === null ? __('setup_bundles_intro_unlimited') : __('setup_sel_intro_quota', ['max' => (int) $maxBundles]) !!}</p>
    <form method="POST" action="{!! route_url('admin.setup.bundles') !!}" id="setup-bundles-form"
          data-room="{!! $room !== null ? (int) $room : '' !!}"
          data-label-selected="{{ __('setup_sel_selected') }}"
          data-label-over="{{ __('setup_sel_over_quota') }}"
          data-label-requires="{{ __('setup_sel_requires_added') }}"
          data-label-required-by="{{ __('setup_sel_required_by') }}">
        {!! csrf_field() !!}        <div class="setup-search">
            <input type="search" class="form-control" id="setup-bundles-search" placeholder="{{ __('setup_sel_search') }}" aria-label="{{ __('setup_sel_search') }}">
        </div>
        @foreach ($_themes as $_theme => $_group)
            <section class="setup-theme" data-theme-group>
                <h4 class="setup-theme__title">{!! __('setup_theme_' . $_theme) !!}</h4>
                <div class="setup-cards">
                    @foreach ($_group as $_slug => $_b)
                        @php $_card = $cards[$_slug] ?? ['icon' => '📦', 'hint' => '']; $_rec = in_array($_slug, $recommended, true); @endphp
                        <label class="setup-card{!! $_rec ? ' setup-card--recommended' : '' !!}" data-bundle-card
                               data-search="{{ mb_strtolower($_b['name'] . ' ' . $_b['description'] . ' ' . ($_card['hint'] ?? '')) }}">
                            <input type="checkbox" name="bundles[]" value="{{ $_slug }}"
                                   data-name="{{ (string) $_b['name'] }}"
                                   data-requires="{{ implode(',', (array) ($_b['requires'] ?? [])) }}"{!! $_rec ? ' checked' : '' !!}>
                            <span class="setup-card__icon" aria-hidden="true">{{ $_card['icon'] }}</span>
                            <span class="setup-card__body">
                                <strong>{{ (string) $_b['name'] }}</strong>
                                @if ($_rec)<span class="badge badge--success">{!! __('setup_sel_recommended') !!}</span>@endif
                                <span class="text-muted text-sm">{{ (string) $_b['description'] }}</span>
                                @if (!empty($_card['hint']))<span class="setup-card__benefit text-sm">{{ (string) $_card['hint'] }}</span>@endif
                                @if (!empty($_b['requires']))<span class="text-muted text-sm">{{ __('setup_sel_requires', ['list' => implode(', ', (array) $_b['requires'])]) }}</span>@endif
                            </span>
                        </label>
                    @endforeach
                </div>
            </section>
        @endforeach
        <p class="form-hint" id="setup-bundles-count" role="status" aria-live="polite"></p>
        <div class="alert alert--warning setup-quota-warning" id="setup-bundles-quota" hidden>
            <span id="setup-bundles-quota-text"></span>
            <a href="{!! route_url('admin.setup') !!}?step=license">{!! __('setup_sel_activate_license') !!}</a>
        </div>
        <div class="form-actions">
            {!! Button::make(__('setup_bundles_install'))->primary()->submit()->render() !!}        </div>
        <p class="form-hint">{!! __('setup_bundles_wait') !!}</p>
    </form>
    @if ($questions !== [])
        <p class="text-sm"><a href="{!! route_url('admin.setup') !!}?step=bundles">{!! __('setup_sel_back_to_questions') !!}</a></p>
    @endif
@endif
<form method="POST" action="{!! route_url('admin.setup.bundles') !!}" class="mt-sm">
    {!! csrf_field() !!}    <input type="hidden" name="skip" value="1">
    {!! Button::make(__('setup_bundles_skip'))->ghost()->submit()->render() !!}</form>
@php echo Card::make()->header(__('setup_bundles_title'))->body(ob_get_clean())->render(); @endphp
<script src="{!! $BASE_URL !!}/assets/js/modules/setup-wizard.js?v={!! asset_version() !!}"></script>
@php unset($_bySlug, $_ordered, $_themes, $_q, $_b, $_slug, $_theme, $_group, $_card, $_rec); @endphp
