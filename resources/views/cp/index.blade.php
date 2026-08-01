{{--
    A Blade CP page rather than an Inertia page: this addon ships no Vite build,
    and core's NonInertiaPage path compiles the yielded Blade into a Vue template
    (statamic/cms resources/js/bootstrap/statamic.js:206-212), so every <ui-*>
    component resolves here exactly as it does in a core page.

    One rule follows from that, and it is not cosmetic: everything that comes out
    of the database goes into a *static attribute*, never into element text. Vue
    interpolates text nodes, so a notification whose message contains a mustache
    would otherwise take the whole screen down with a compile error. Static
    attribute values are never interpolated.
--}}
@extends('statamic::layout')
@section('title', __('notifications::cp.title'))

@section('content')

    <ui-header title="{{ __('notifications::cp.title') }}" icon="bell" />

    <ui-description text="{{ __('notifications::cp.intro') }}" class="mb-4" />

    <ui-listing
        url="{{ cp_route('notifications.listing') }}"
        :columns='{{ json_encode($columns) }}'
        :filters='{{ json_encode($filters) }}'
        preferences-prefix="{{ $preferencesPrefix }}"
        sort-column="created_at"
        sort-direction="desc"
        push-query
    >
        <template #cell-created_at="{ row, value }">
            <ui-button :href="row.url" :text="value" variant="ghost" size="sm" inset />
        </template>

        <template #cell-type="{ value }">
            <ui-text variant="code" :text="value" />
        </template>

        <template #cell-recipient="{ value }">
            <ui-text :text="value ?? '—'" />
        </template>

        <template #cell-read_at="{ row, value }">
            <ui-text v-if="row.is_read" :text="value" />
            <ui-badge v-else color="amber" text="{{ __('notifications::cp.unread') }}" />
        </template>

        <template #cell-digested_at="{ value }">
            <ui-text variant="subtle" :text="value ?? '—'" />
        </template>
    </ui-listing>

@endsection
