@extends('layout.app')
@section('content')
@php
use App\View\Components\Pagination;

/** @var array[] $notifications */
/** @var int     $page */
/** @var int     $totalPages */
@endphp

<div class="page-header">
    <h2 class="page-header__title">{!! __('notifications') !!}</h2>
    @if (!empty($notifications))
    <div class="page-header__actions">
        <form method="POST" action="{!! route_url('notifications.read_all') !!}">
            {!! csrf_field() !!}            <button type="submit" class="btn btn--ghost btn--sm">{!! __('mark_all_read') !!}</button>
        </form>
        <form method="POST" action="{!! route_url('notifications.delete_all') !!}" data-confirm="{{ __('delete_all_notifications_confirm') }}">
            {!! csrf_field() !!}            <button type="submit" class="btn btn--ghost btn--sm">{!! __('delete_all_notifications') !!}</button>
        </form>
    </div>
    @endif
</div>

@if (empty($notifications))
    <div class="empty-state empty-state--bell">
        <p>{!! __('no_notifications') !!}</p>
    </div>
@else
    <div class="notif-list">
        @foreach ($notifications as $n)
            @php $isRead = (int) ($n['is_read'] ?? 0); @endphp
            <div class="notif-item{!! $isRead ? '' : ' notif-item--unread' !!}">
                <a href="{!! route_url('notifications.open', ['id' => (int) $n['id']]) !!}" class="notif-item__link">
                    <span class="notif-item__icon">{!! notification_type_icon($n['type'] ?? '') !!}</span>
                    <div class="notif-item__content">
                        <div class="notif-item__title">{{ notification_type_label($n['type'] ?? '') }}</div>
                        <div class="notif-item__body">{{ notification_body($n) }}</div>
                        <div class="notif-item__time">{{ substr($n['created_at'] ?? '', 0, 16) }}</div>
                    </div>
                </a>
                @if (!$isRead)
                <form method="POST" action="{!! $BASE_URL !!}/notifications/{!! (int) $n['id'] !!}/read" class="notif-item__action">
                    {!! csrf_field() !!}                    <button type="submit" class="btn btn--ghost btn--xs" title="{!! __('mark_as_read') !!}">✓</button>
                </form>
                @endif
            </div>
        @endforeach
    </div>
    @if (($totalPages ?? 1) > 1)
        {!! Pagination::make((int) $page, (int) $totalPages)->render() !!}    @endif
@endif

@endsection
