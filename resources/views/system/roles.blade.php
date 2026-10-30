@extends('layout.app')
@section('content')
@php
use App\View\Components\Badge;
use App\View\Components\Button;
use App\View\Components\Flash;

/** @var array $roles             Liste des rôles */
/** @var array $assignment_counts Map role_id => nombre d'affectations */

echo Flash::fromSession('success', [
    'created' => __('save_success'),
    'updated' => __('save_success'),
    'deleted' => __('save_success'),
])->render();
echo Flash::fromSession('error', [
    'role_in_use' => __('role_in_use'),
])->render();
@endphp
<div class="page-header">
    <h2 class="page-header__title">{!! __('roles') !!}</h2>
</div>

@include('_partials._settings-tabs')

<div class="page-header page-header--end">
    <a href="{!! route_url('admin.roles.create') !!}" class="btn btn--primary">{!! __('new_role') !!}</a>
</div>

<div class="card">
    <div class="table-wrap">
        <table class="data-table" data-mob-stack>
            <thead>
                <tr>
                    <th>{!! __('name') !!}</th>
                    <th>{!! __('description') !!}</th>
                    <th>{!! __('role_holders') !!}</th>
                    <th class="td-center">{!! __('edit') !!}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($roles as $role)
                @php $rid = (int) $role['id']; @endphp
                <tr class="tr--clickable" data-href="{{ route_url('admin.roles.edit', ['id' => $rid]) }}">
                    <td data-label="{{ __('name') }}">
                        @if (!empty($role['color']))
                            <span class="color-swatch {!! style_class(['background' => $role['color']]) !!}"></span>
                        @endif
                        {{ $role['name'] ?? '' }}                        @if (!empty($role['is_system']))
                            {!! Badge::make(__('system_role'))->render() !!}                        @endif
                    </td>
                    <td data-label="{{ __('description') }}" class="td-muted">
                        {{ $role['description'] ?? '' }}                    </td>
                    <td data-label="{{ __('role_holders') }}">
                        {!! (int) ($assignment_counts[$rid] ?? 0) !!}                    </td>
                    <td data-label="{{ __('edit') }}" class="td-center td-nowrap">
                        <a href="{!! route_url('admin.roles.edit', ['id' => $rid]) !!}" class="btn btn--ghost btn--xs">{!! __('edit') !!}</a>
                        @if (empty($role['is_system']))
                        <form method="POST" action="{!! route_url('admin.roles.delete', ['id' => $rid]) !!}"
                              class="d-inline" data-confirm="{{ __('role_delete_confirm') }}">
                            {!! csrf_field() !!}                            {!! Button::make(__('delete'))->danger()->xs()->submit()->render() !!}                        </form>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

@endsection
