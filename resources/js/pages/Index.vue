<script setup>
import { Head, router } from '@statamic/cms/inertia';
import { Header, Description, Listing, Text, Badge, Button } from '@statamic/cms/ui';

/**
 * The notification inspector's index — a straight port of the Blade page it
 * replaces, cell for cell.
 *
 * The Blade version had to push every database value into a *static* attribute
 * because core compiled the yielded Blade into a Vue template, so a mustache in
 * a producer-supplied message was a page-breaking compile error. Here the rows
 * arrive over the listing's XHR as data and are never compiled, so that rule
 * and the escaping gymnastics it forced are simply gone.
 */
const props = defineProps({
    // Statamic\CP\Column::toArray() shape, from NotificationController::columns().
    columns: { type: Array, required: true },
    // Scope::filters('notifications') — type, read state, recipient.
    filters: { type: Array, required: true },
    listingUrl: { type: String, required: true },
    preferencesPrefix: { type: String, required: true },
});

// Client-side reloads are not used by this screen (the Listing owns its own
// XHR), but the listing emits `refreshing` after a bulk action or a filter
// reset and core's pages answer it with a reload.
const reloadPage = () => router.reload({ preserveScroll: true });
</script>

<template>
    <Head :title="__('notifications::cp.title')" />

    <div class="max-w-page mx-auto">
        <Header :title="__('notifications::cp.title')" icon="bell" />

        <Description :text="__('notifications::cp.intro')" class="mb-4" />

        <Listing
            :url="listingUrl"
            :columns="columns"
            :filters="filters"
            :preferences-prefix="preferencesPrefix"
            sort-column="created_at"
            sort-direction="desc"
            push-query
            @refreshing="reloadPage"
        >
            <template #cell-created_at="{ row, value }">
                <Button :href="row.url" :text="value" variant="ghost" size="sm" inset />
            </template>

            <template #cell-type="{ value }">
                <Text variant="code" :text="value" />
            </template>

            <template #cell-recipient="{ value }">
                <Text :text="value ?? '—'" />
            </template>

            <template #cell-read_at="{ row, value }">
                <Text v-if="row.is_read" :text="value" />
                <Badge v-else color="amber" :text="__('notifications::cp.unread')" />
            </template>

            <template #cell-digested_at="{ value }">
                <Text variant="subtle" :text="value ?? '—'" />
            </template>
        </Listing>
    </div>
</template>
