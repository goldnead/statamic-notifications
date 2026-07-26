@extends('statamic::layout')
@section('title', __('notifications::cp.detail_title'))

@section('content')

    <header class="notif-inspector__header">
        <h1><code>{{ $notification->type }}</code></h1>
        <p class="notif-inspector__intro">
            {{ $notification->created_at?->format('Y-m-d H:i:s') }}
            · {{ $notification->email ?? $notification->user_id ?? $notification->contact_uuid }}
        </p>
    </header>

    <div class="card notif-inspector__panel">
        <dl class="notif-inspector__definitions">
            <dt>message</dt>
            <dd>{{ $notification->message ?? '—' }}</dd>
            <dt>link</dt>
            <dd>{{ $notification->link ?? '—' }}</dd>
            <dt>actor</dt>
            <dd>{{ $notification->actor_name ?? $notification->actor_id ?? '—' }}</dd>
            <dt>dedupe_key</dt>
            <dd><code>{{ $notification->dedupe_key ?? '—' }}</code></dd>
            <dt>{{ __('notifications::cp.col_read') }}</dt>
            <dd>{{ $notification->read_at?->format('Y-m-d H:i:s') ?? __('notifications::cp.unread') }}</dd>
            <dt>{{ __('notifications::cp.col_digested') }}</dt>
            <dd>{{ $notification->digested_at?->format('Y-m-d H:i:s') ?? '—' }}</dd>
        </dl>
    </div>

    <div class="card notif-inspector__panel">
        <h2 class="notif-inspector__label">{{ __('notifications::cp.detail_data') }}</h2>
        <pre class="notif-inspector__pre">{{ json_encode($notification->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
    </div>
@endsection

{{-- Deliberately in 'scripts', not 'content': Statamic 6 compiles the yielded
     Blade of a CP page into a Vue component template, and Vue's template
     compiler strips <style> tags. The 'scripts' yield sits outside the
     #statamic mount point, so the rules survive. --}}
@section('scripts')
    @include('notifications::cp._styles')
@endsection
