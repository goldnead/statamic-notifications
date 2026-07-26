@extends('statamic::layout')
@section('title', __('notifications::cp.title'))

@section('content')
    <header class="mb-6">
        <h1>{{ __('notifications::cp.title') }}</h1>
        <p class="text-gray-500 text-sm mt-1">{{ __('notifications::cp.intro') }}</p>
    </header>

    <div class="card p-4 mb-4">
        <form method="GET" class="flex flex-wrap items-end gap-3">
            <div>
                <label class="text-xs font-medium block mb-1">{{ __('notifications::cp.filter_type') }}</label>
                <input type="text" name="type" class="input-text" value="{{ $filters['type'] ?? '' }}">
            </div>
            <div>
                <label class="text-xs font-medium block mb-1">{{ __('notifications::cp.filter_user') }}</label>
                <input type="text" name="user_id" class="input-text" value="{{ $filters['user_id'] ?? '' }}">
            </div>
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="unread" value="1" @checked(! empty($filters['unread']))>
                {{ __('notifications::cp.filter_unread') }}
            </label>
            <div class="flex gap-2">
                <button type="submit" class="btn-primary">{{ __('notifications::cp.filter_submit') }}</button>
                <a href="{{ cp_route('notifications.index') }}" class="btn">{{ __('notifications::cp.filter_reset') }}</a>
            </div>
        </form>
    </div>

    @if ($notifications->isEmpty())
        <div class="card p-6 text-center text-gray-500">{{ __('notifications::cp.empty') }}</div>
    @else
        <div class="card p-0 overflow-x-auto">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>{{ __('notifications::cp.col_created') }}</th>
                        <th>{{ __('notifications::cp.col_type') }}</th>
                        <th>{{ __('notifications::cp.col_recipient') }}</th>
                        <th>{{ __('notifications::cp.col_read') }}</th>
                        <th>{{ __('notifications::cp.col_digested') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($notifications as $notification)
                        <tr>
                            <td>
                                <a href="{{ cp_route('notifications.show', ['id' => $notification->id]) }}">
                                    {{ $notification->created_at?->format('Y-m-d H:i') }}
                                </a>
                            </td>
                            <td><code>{{ $notification->type }}</code></td>
                            <td>{{ $notification->email ?? $notification->user_id ?? $notification->contact_uuid }}</td>
                            <td>
                                @if ($notification->read_at)
                                    {{ $notification->read_at->format('Y-m-d H:i') }}
                                @else
                                    <span class="badge-sm">{{ __('notifications::cp.unread') }}</span>
                                @endif
                            </td>
                            <td>{{ $notification->digested_at?->format('Y-m-d H:i') ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4">{{ $notifications->links() }}</div>
    @endif
@endsection
