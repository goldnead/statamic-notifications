{{--
    See the note at the top of index.blade.php. Row data goes into static
    attributes only: `message` and `dedupe_key` are producer-supplied strings and
    a mustache in either of them would otherwise break the page at compile time.
    <ui-description> and <ui-panel heading> both render through v-html, so they
    carry translated strings and nothing else.
--}}
@extends('statamic::layout')
@section('title', __('notifications::cp.detail_title'))

@section('content')

    <ui-header title="{{ $notification->type }}" icon="bell">
        <ui-button
            href="{{ cp_route('notifications.index') }}"
            text="{{ __('notifications::cp.back_to_index') }}"
            icon="arrow-left"
        />
    </ui-header>

    <ui-panel heading="{{ __('notifications::cp.detail_title') }}">
        <ui-card>
            <ui-table>
                <ui-table-rows>
                    @foreach ([
                        'field_created' => $notification->created_at?->format('Y-m-d H:i:s'),
                        'col_recipient' => $notification->email ?? $notification->user_id ?? $notification->contact_uuid,
                        'field_message' => $notification->message,
                        'field_link' => $notification->link,
                        'field_actor' => $notification->actor_name ?? $notification->actor_id,
                        'field_dedupe_key' => $notification->dedupe_key,
                        'col_read' => $notification->read_at?->format('Y-m-d H:i:s') ?? __('notifications::cp.unread'),
                        'col_digested' => $notification->digested_at?->format('Y-m-d H:i:s'),
                    ] as $key => $value)
                        <ui-table-row>
                            <ui-table-cell>
                                <ui-text variant="strong" text="{{ __('notifications::cp.'.$key) }}" />
                            </ui-table-cell>
                            <ui-table-cell>
                                <ui-text text="{{ $value ?? '—' }}" />
                            </ui-table-cell>
                        </ui-table-row>
                    @endforeach
                </ui-table-rows>
            </ui-table>
        </ui-card>
    </ui-panel>

    <ui-panel heading="{{ __('notifications::cp.detail_data') }}">
        <ui-card>
            <ui-text
                as="pre"
                variant="code"
                class="block overflow-x-auto"
                text="{{ json_encode($notification->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}"
            />
        </ui-card>
    </ui-panel>

@endsection
