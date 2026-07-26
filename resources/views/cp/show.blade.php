@extends('statamic::layout')
@section('title', __('notifications::cp.detail_title'))

@section('content')
    <header class="mb-6">
        <h1><code>{{ $notification->type }}</code></h1>
        <p class="text-gray-500 text-sm mt-1">
            {{ $notification->created_at?->format('Y-m-d H:i:s') }}
            · {{ $notification->email ?? $notification->user_id ?? $notification->contact_uuid }}
        </p>
    </header>

    <div class="card p-4 mb-4">
        <dl class="text-sm">
            <dt class="text-gray-500">message</dt>
            <dd class="mb-2">{{ $notification->message ?? '—' }}</dd>
            <dt class="text-gray-500">link</dt>
            <dd class="mb-2">{{ $notification->link ?? '—' }}</dd>
            <dt class="text-gray-500">actor</dt>
            <dd class="mb-2">{{ $notification->actor_name ?? $notification->actor_id ?? '—' }}</dd>
            <dt class="text-gray-500">dedupe_key</dt>
            <dd class="mb-2"><code>{{ $notification->dedupe_key ?? '—' }}</code></dd>
            <dt class="text-gray-500">{{ __('notifications::cp.col_read') }}</dt>
            <dd class="mb-2">{{ $notification->read_at?->format('Y-m-d H:i:s') ?? __('notifications::cp.unread') }}</dd>
            <dt class="text-gray-500">{{ __('notifications::cp.col_digested') }}</dt>
            <dd>{{ $notification->digested_at?->format('Y-m-d H:i:s') ?? '—' }}</dd>
        </dl>
    </div>

    <div class="card p-4">
        <h2 class="text-sm font-medium mb-2">{{ __('notifications::cp.detail_data') }}</h2>
        <pre class="text-xs overflow-x-auto">{{ json_encode($notification->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
    </div>
@endsection
