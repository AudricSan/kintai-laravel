@extends('layout.app')
@section('content')
@php
use App\View\Components\Button;
use App\View\Components\Flash;
use App\View\Components\Modal;

/** @var string $mode                 'create'|'edit' */
/** @var array  $role                 Données du rôle */
/** @var array  $permission_categories Catégorie => [actions] (PermissionCatalog::CATEGORIES) */
/** @var array  $action_label_keys    Action => clé de traduction */
/** @var array  $granted_permissions  Clés de permission déjà accordées (mode edit) */
/** @var array  $granted_global_permissions Sous-ensemble de $granted_permissions en portée "toutes les boutiques" (mode edit) */
/** @var array  $holders              ['assignment_id','user_name','initials','color','scope_label'][] (mode edit) */
$mode ??= 'create';
$granted_global_permissions ??= [];
$assignable_users ??= [];
$isSystem = !empty($role['is_system']);

echo Flash::fromSession('success', [
    'holder_added'   => __('role_holder_added'),
    'holder_removed' => __('role_holder_removed'),
])->render();
echo Flash::fromSession('error', [
    'invalid_name'   => __('role_invalid_name'),
    'invalid_holder' => __('role_invalid_holder'),
])->render();
@endphp

<div class="page-header">
    <h2 class="page-header__title">{!! $mode === 'create' ? __('new_role') : htmlspecialchars($role['name'] ?? '') !!}</h2>
    <a href="{!! route_url('admin.roles') !!}" class="btn btn--ghost">← {!! __('roles') !!}</a>
</div>

@php $hasHolders = $mode === 'edit'; @endphp
<form method="POST" action="{!! $mode === 'create' ? route_url('admin.roles.store') : route_url('admin.roles.update', ['id' => (int) $role['id']]) !!}">
    {!! csrf_field() !!}
    @if ($isSystem)
    <!-- Rôle système (Owner) : pas de permissions à éditer, la grille
         20/80 (pensée pour équilibrer identité+utilisateurs à gauche et
         permissions à droite) n'a pas lieu d'être ; simple pile. -->
    <div class="card">
        <div class="card-body form-stack">
            <p class="form-hint">{!! __('system_role') !!}</p>
            <div class="form-group">
                <label class="form-label">{!! __('color') !!}</label>
                <input type="color" name="color" class="input-color"
                       value="{{ $role['color'] ?? '#6366f1' }}" disabled>
            </div>
            <div class="form-group">
                <label class="form-label">{!! __('description') !!}</label>
                <textarea name="description" class="form-control" rows="2" disabled>{{ $role['description'] ?? '' }}</textarea>
            </div>
        </div>
    </div>
    <div class="card card--mb">
        <div class="card-body">
            <h4 class="section-title">{!! __('role_holders') !!} <span class="page-count">({!! count($holders) !!})</span></h4>
            @php $removable = true; echo $__env->make('_partials._role-holders-list', \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); @endphp
            @if (!empty($assignable_users))
            {!! Button::make(__('add'))->outline()->sm()->attrs(['type' => 'button', 'data-on-click' => 'openModal', 'data-args' => '["addRoleHolderModal"]'])->render() !!}            @endif
        </div>
    </div>
    @else

    <div class="role-form-grid{!! $hasHolders ? '' : ' role-form-grid--single' !!}">
        <div class="card rf-info">
            <div class="card-body form-stack">
                <div class="form-group">
                    <label class="form-label form-label--required">{!! __('name') !!}</label>
                    <input type="text" name="name" class="form-control"
                           value="{{ $role['name'] ?? '' }}" required>
                </div>
                <div class="form-group">
                    <label class="form-label">{!! __('color') !!}</label>
                    <input type="color" name="color" class="input-color"
                           value="{{ $role['color'] ?? '#6366f1' }}">
                </div>
                <div class="form-group">
                    <label class="form-label">{!! __('description') !!}</label>
                    <textarea name="description" class="form-control" rows="2">{{ $role['description'] ?? '' }}</textarea>
                </div>
                <div class="form-group">
                    <label class="form-toggle form-toggle--labeled">
                        <input type="hidden" name="is_manager" value="0">
                        <input type="checkbox" name="is_manager" value="1" class="form-toggle__input" {!! !empty($role['is_manager']) ? 'checked' : '' !!}>
                        <span class="form-toggle__track"></span>
                        <span>{!! __('role_is_manager') !!}</span>
                    </label>
                    <p class="form-hint">{!! __('role_is_manager_hint') !!}</p>
                </div>
            </div>
        </div>

        @if ($hasHolders)
        <div class="card rf-holders">
            <div class="card-body">
                <h4 class="section-title">{!! __('role_holders') !!} <span class="page-count">({!! count($holders) !!})</span></h4>
                @php $removable = true; echo $__env->make('_partials._role-holders-list', \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); @endphp

                @if (!empty($assignable_users))
                {!! Button::make(__('add'))->outline()->sm()->attrs(['type' => 'button', 'data-on-click' => 'openModal', 'data-args' => '["addRoleHolderModal"]'])->render() !!}                @endif
            </div>
        </div>
        @endif

        <div class="card rf-permissions">
            <div class="card-body form-stack">
                <h4 class="section-title">{!! __('permissions') !!}</h4>
                <div class="perm-grid" data-perm-grid
                     data-select-all-label="{{ __('select_all') }}"
                     data-deselect-all-label="{{ __('deselect_all') }}">
                    @foreach ($permission_categories as $category => $actions)
                    @php
                        $catGranted = 0;
                        foreach ($actions as $action) {
                            if (in_array($category . '.' . $action, $granted_permissions, true)) {
                                $catGranted++;
                            }
                        }
                    @endphp
                    <div class="perm-card" data-perm-card>
                        <div class="perm-card__header">
                            <span class="perm-card__title">{!! __('perm_category_' . $category) !!}</span>
                            <div class="perm-card__tools">
                                <span class="perm-card__count" data-perm-count>{!! $catGranted !!}/{!! count($actions) !!}</span>
                                <button type="button" class="perm-card__toggle-all" data-perm-toggle-all>{!! $catGranted === count($actions) ? __('deselect_all') : __('select_all') !!}</button>
                            </div>
                        </div>
                        <div class="perm-card__list">
                            @foreach ($actions as $action)
                            @php
                                $key            = $category . '.' . $action;
                                $fieldSuffix    = str_replace('.', '_', $key);
                                $fieldName      = 'perm_' . $fieldSuffix;
                                $scopeFieldName = 'scope_' . $fieldSuffix;
                                $scopeFieldId   = 'scope-' . str_replace('_', '-', $fieldSuffix);
                                $labelKey       = $action_label_keys[$action] ?? $action;
                                $checked        = in_array($key, $granted_permissions, true);
                                $globalChecked  = in_array($key, $granted_global_permissions, true);
                            @endphp
                            <div class="perm-row" data-perm-row>
                                <label class="perm-toggle">
                                    <span class="perm-toggle__text">{!! __($labelKey) !!}</span>
                                    <input type="checkbox" name="{{ $fieldName }}" value="1" class="perm-toggle__input" data-perm-checkbox
                                           {!! $checked ? 'checked' : '' !!}>
                                    <span class="perm-toggle__track"></span>
                                </label>
                                <span class="perm-scope">
                                    <input type="checkbox" id="{{ $scopeFieldId }}" name="{{ $scopeFieldName }}" value="global"
                                           class="perm-scope__input" data-perm-scope-checkbox
                                           {!! $globalChecked ? 'checked' : '' !!} {!! $checked ? '' : 'disabled' !!}>
                                    <label for="{{ $scopeFieldId }}" class="perm-scope__label" title="{{ __('perm_scope_global_hint') }}">{!! __('perm_scope_global') !!}</label>
                                </span>
                            </div>
                            @endforeach
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    <div class="form-actions">
        {!! Button::make(__('save'))->primary()->submit()->render() !!}        <a href="{!! route_url('admin.roles') !!}" class="btn btn--ghost">{!! __('cancel') !!}</a>
    </div>
    @endif
</form>

@if ($hasHolders)
<!-- Formulaire de retrait vide et partagé : chaque bouton "×" de
     _role-holders-list.php le cible via form=/formaction (voir ce partiel). -->
<form id="roleHolderRemoveForm" method="POST">
    {!! csrf_field() !!}</form>

@php if (!empty($assignable_users)):
    ob_start(); @endphp
    <form id="roleHolderAddForm" method="POST" action="{!! route_url('admin.roles.holders.add', ['id' => (int) $role['id']]) !!}">
        {!! csrf_field() !!}        <div class="store-toggle-list">
            @foreach ($assignable_users as $u)
                @php $uName = trim(($u['last_name'] ?? '') . ' ' . ($u['first_name'] ?? '')) ?: ($u['email'] ?? '#' . $u['id']); @endphp
                <div class="store-toggle-list__item check-label">
                    <label class="form-toggle">
                        <input type="checkbox" name="user_ids[]" value="{!! (int) $u['id'] !!}" class="form-toggle__input">
                        <span class="form-toggle__track"></span>
                    </label>
                    <span>{{ $uName }}@if (!empty($u['store_names'])) <span class="text-sm-muted">({{ $u['store_names'] }})</span>@endif</span>
                </div>
            @endforeach
        </div>
    </form>
    @php
    $addRoleHolderFooter = Button::make(__('add'))->primary()->submit()->attrs(['form' => 'roleHolderAddForm'])->render()
        . ' ' . Button::make(__('cancel'))->ghost()->attrs(['type' => 'button', 'data-on-click' => 'closeModal', 'data-args' => '["addRoleHolderModal"]'])->render();
    echo Modal::make('addRoleHolderModal')
        ->title(__('role_add_holders_hint'))
        ->body(ob_get_clean())
        ->footer($addRoleHolderFooter)
        ->render();
endif; @endphp
@endif

@if (!$isSystem)
<script src="{!! $BASE_URL !!}/assets/js/modules/permission-editor.js"></script>
@endif

@endsection
