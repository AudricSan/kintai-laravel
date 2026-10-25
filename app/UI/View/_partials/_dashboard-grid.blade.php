@php
/**
 * Grille des widgets d'un tableau de bord (12 colonnes, mode « flux »), dans l'ordre, la largeur et la hauteur choisis
 * par l'utilisateur (DashboardLayout). Largeur et hauteur sont portées par des classes (dash-w-N, dash-h-N), jamais par
 * un attribut style (CSP).
 *
 * Chaque widget peut être replié (bouton ▾ en haut à droite, hors édition). Le bouton « Personnaliser » passe la grille
 * en mode édition (dashboard-layout.js) : chaque widget reçoit une barre d'outils — glisser-déposer, monter/descendre,
 * largeur −/+ (en colonnes), hauteur −/+ (en lignes), retrait. Chaque changement est enregistré.
 *
 * @var array<string, string>                                  $_dashBlocks HTML de chaque widget (clé => html)
 * @var array{order: list<string>, widths: array<string,string|int>, heights?: array<string,int>, collapsed?: list<string>, density?: string} $_dashLayout
 * @var array<string, string>                                  $_dashLabels Libellés des widgets
 * @var string                                                 $_dashSaveUrl    Disposition (ordre, taille, repli)
 * @var string                                                 $_dashWidgetsUrl Ajout / retrait d'un widget
 * @var array<string, array{0: int, 1: int}>                   $widget_limits   Bornes de largeur des widgets de bundles
 * @var array<string, int>                                     $widget_refresh  Rafraîchissement des widgets de bundles (secondes)
 */
use kintai\Core\Extension\DashboardLayout;

$_dashLimits    = $widget_limits ?? [];
$_dashRefresh   = ($widget_refresh ?? []) + DashboardLayout::CORE_REFRESH;
$_dashCollapsed = $_dashLayout['collapsed'] ?? [];
$_dashCompact   = ($_dashLayout['density'] ?? '') === DashboardLayout::COMPACT;
@endphp
<div class="dash-grid{!! $_dashCompact ? ' dash-grid--compact' : '' !!}" data-dash-grid data-save-url="{{ $_dashSaveUrl }}"
     data-widgets-url="{{ $_dashWidgetsUrl ?? '' }}"
     data-density="{!! $_dashCompact ? 'compact' : 'comfortable' !!}"
     data-label-error="{{ __('dashboard_layout_save_failed') }}">
    @foreach ($_dashLayout['order'] as $_dk)
        @php if (trim((string) ($_dashBlocks[$_dk] ?? '')) === '') continue; @endphp
        @php
        [$_dMin, $_dMax] = $_dashLimits[$_dk] ?? [1, DashboardLayout::COLUMNS];
        $_dc        = max($_dMin, min($_dMax, DashboardLayout::columns($_dashLayout['widths'][$_dk] ?? DashboardLayout::FULL)));
        $_dh        = max(1, min(DashboardLayout::MAX_ROWS, (int) ($_dashLayout['heights'][$_dk] ?? 1)));
        $_dFolded   = in_array($_dk, $_dashCollapsed, true);
        $_dl        = (string) ($_dashLabels[$_dk] ?? $_dk);
        @endphp
        <section id="dash-widget-{{ $_dk }}"
                 class="dash-grid__item dash-w-{!! $_dc !!} dash-h-{!! $_dh !!}{!! $_dFolded ? ' dash-grid__item--collapsed' : '' !!}"
                 data-widget-label="{{ $_dl }}" data-widget-key="{{ $_dk }}"
                 data-widget-width="{!! $_dc !!}" data-widget-height="{!! $_dh !!}"
                 data-widget-min="{!! (int) $_dMin !!}" data-widget-max="{!! (int) $_dMax !!}"
                 data-widget-refresh="{!! (int) ($_dashRefresh[$_dk] ?? 0) !!}"
                 data-widget-collapsed="{!! $_dFolded ? '1' : '0' !!}">
            <button type="button" class="dash-grid__fold" data-dash-fold
                    aria-expanded="{!! $_dFolded ? 'false' : 'true' !!}"
                    title="{{ $_dFolded ? __('dashboard_widget_expand') : __('dashboard_widget_collapse') }}"
                    aria-label="{{ ($_dFolded ? __('dashboard_widget_expand') : __('dashboard_widget_collapse')) . ' — ' . $_dl }}"
                    data-label-collapse="{{ __('dashboard_widget_collapse') }}"
                    data-label-expand="{{ __('dashboard_widget_expand') }}"><span aria-hidden="true">▾</span></button>
            <div class="dash-grid__collapsed-label">{{ $_dl }}</div>
            <div class="dash-grid__tools" role="toolbar" aria-label="{{ $_dl }}">
                <button type="button" class="dash-grid__handle" data-dash-handle
                        title="{{ __('dashboard_widget_drag') }}"
                        aria-label="{{ __('dashboard_widget_drag') . ' — ' . $_dl }}">⠿</button>
                <span class="dash-grid__name">{{ $_dl }}</span>
                <button type="button" class="btn btn--ghost btn--sm" data-dash-move="up"
                        aria-label="{{ __('dashboard_widget_up') . ' — ' . $_dl }}">↑</button>
                <button type="button" class="btn btn--ghost btn--sm" data-dash-move="down"
                        aria-label="{{ __('dashboard_widget_down') . ' — ' . $_dl }}">↓</button>
                <span class="dash-grid__size" role="group" aria-label="{{ __('dashboard_widget_width') . ' — ' . $_dl }}">
                    <button type="button" class="btn btn--ghost btn--sm" data-dash-size="width" data-step="-1"
                            aria-label="{{ __('dashboard_widget_narrower') . ' — ' . $_dl }}">−</button>
                    <span class="dash-grid__size-value" data-dash-size-value="width" title="{{ __('dashboard_widget_width') }}">{!! $_dc !!}/12</span>
                    <button type="button" class="btn btn--ghost btn--sm" data-dash-size="width" data-step="1"
                            aria-label="{{ __('dashboard_widget_wider') . ' — ' . $_dl }}">+</button>
                </span>
                <span class="dash-grid__size" role="group" aria-label="{{ __('dashboard_widget_height') . ' — ' . $_dl }}">
                    <button type="button" class="btn btn--ghost btn--sm" data-dash-size="height" data-step="-1"
                            aria-label="{{ __('dashboard_widget_shorter') . ' — ' . $_dl }}">−</button>
                    <span class="dash-grid__size-value" data-dash-size-value="height" title="{{ __('dashboard_widget_height') }}">{!! $_dh !!}↕</span>
                    <button type="button" class="btn btn--ghost btn--sm" data-dash-size="height" data-step="1"
                            aria-label="{{ __('dashboard_widget_taller') . ' — ' . $_dl }}">+</button>
                </span>
                <button type="button" class="btn btn--ghost btn--sm dash-grid__remove" data-dash-remove
                        title="{{ __('dashboard_widget_remove') }}"
                        aria-label="{{ __('dashboard_widget_remove') . ' — ' . $_dl }}">✕</button>
            </div>
            <div class="dash-grid__content">{!! $_dashBlocks[$_dk] !!}</div>
        </section>
    @endforeach
</div>
<script src="{!! $BASE_URL !!}/assets/js/modules/dashboard-layout.js"></script>
<script src="{!! $BASE_URL !!}/assets/js/modules/dashboard-live.js"></script>
@php unset($_dashRefresh, $_dk, $_dc, $_dh, $_dl, $_dFolded, $_dMin, $_dMax, $_dashLimits, $_dashCollapsed, $_dashCompact); @endphp
