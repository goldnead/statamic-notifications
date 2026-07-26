@extends('statamic::layout')
@section('title', __('notifications::cp.title'))

@section('content')

    <header class="notif-inspector__header">
        <h1>{{ __('notifications::cp.title') }}</h1>
        <p class="notif-inspector__intro">{{ __('notifications::cp.intro') }}</p>
    </header>

    <div class="card notif-inspector__panel">
        <form method="GET" class="notif-inspector__filters">
            <div class="notif-inspector__field">
                <label class="notif-inspector__label" for="filter-type">{{ __('notifications::cp.filter_type') }}</label>
                <input id="filter-type" type="text" name="type" class="notif-inspector__input" value="{{ $filters['type'] ?? '' }}">
            </div>
            <div class="notif-inspector__field">
                <label class="notif-inspector__label" for="filter-user">{{ __('notifications::cp.filter_user') }}</label>
                <input id="filter-user" type="text" name="user_id" class="notif-inspector__input" value="{{ $filters['user_id'] ?? '' }}">
            </div>
            <div class="notif-inspector__field notif-inspector__field--inline">
                <input id="filter-unread" type="checkbox" name="unread" value="1" @checked(! empty($filters['unread']))>
                <label class="notif-inspector__label" for="filter-unread">{{ __('notifications::cp.filter_unread') }}</label>
            </div>
            <div class="notif-inspector__field">
                <button type="submit" class="notif-inspector__button notif-inspector__button--primary">{{ __('notifications::cp.filter_submit') }}</button>
            </div>
            <div class="notif-inspector__field">
                <a href="{{ cp_route('notifications.index') }}" class="notif-inspector__button notif-inspector__button--plain">{{ __('notifications::cp.filter_reset') }}</a>
            </div>
        </form>
    </div>

    @if ($notifications->isEmpty())
        <div class="card notif-inspector__empty">{{ __('notifications::cp.empty') }}</div>
    @else
        <div class="card notif-inspector__scroll">
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
                                    <span class="notif-inspector__badge">{{ __('notifications::cp.unread') }}</span>
                                @endif
                            </td>
                            <td>{{ $notification->digested_at?->format('Y-m-d H:i') ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="notif-inspector__pagination">{{ $notifications->links() }}</div>
    @endif
@endsection

{{-- Deliberately in 'scripts', not 'content': Statamic 6 compiles the yielded
     Blade of a CP page into a Vue component template, and Vue's template
     compiler strips <style> tags. The 'scripts' yield sits outside the
     #statamic mount point, so the rules survive. --}}
@section('scripts')
    @include('notifications::cp._styles')
@endsection
