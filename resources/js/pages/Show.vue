<script setup>
import { computed } from 'vue';
import { Head } from '@statamic/cms/inertia';
import {
    Header, Button, CommandPaletteItem, Panel, Card, Table, TableRows, TableRow,
    TableCell, Text,
} from '@statamic/cms/ui';

/**
 * A single notification, field by field — the port of show.blade.php.
 *
 * The field list is built here rather than server-side so the labels stay next
 * to the markup that renders them, exactly as the Blade `@foreach` had them.
 * The controller hands over one flat, already-formatted row; nothing on this
 * page reaches for config or for a model.
 */
const props = defineProps({
    // { type, created_at, recipient, message, link, actor, dedupe_key, read_at, digested_at, data }
    notification: { type: Object, required: true },
    indexUrl: { type: String, required: true },
});

const fields = computed(() => [
    ['field_created', props.notification.created_at],
    ['col_recipient', props.notification.recipient],
    ['field_message', props.notification.message],
    ['field_link', props.notification.link],
    ['field_actor', props.notification.actor],
    ['field_dedupe_key', props.notification.dedupe_key],
    ['col_read', props.notification.read_at ?? __('notifications::cp.unread')],
    ['col_digested', props.notification.digested_at],
]);

// PHP's JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE and
// JSON.stringify(value, null, 4) produce the same text: JS escapes neither
// slashes nor non-ASCII. Formatting here keeps the payload a real object in
// the props, so a future addition can render it as something better than a
// blob without a controller change.
const payload = computed(() => JSON.stringify(props.notification.data ?? null, null, 4));
</script>

<template>
    <Head :title="[notification.type, __('notifications::cp.title')]" />

    <!--
        No width wrapper of its own, exactly as the Blade page had none: the
        Layout's `max-w-page` already applies, and a narrower custom container
        would sit at a different width from every core screen and ignore the
        header's expand-layout toggle.
    -->
    <div>
        <Header :title="notification.type" icon="bell">
            <!--
                The only action this read-only screen has, registered in the
                command palette the way every core page-level action is.
                The button keeps its own props rather than taking them from the
                slot scope, so it renders identically whether or not the palette
                is listening.
            -->
            <CommandPaletteItem
                category="Actions"
                :text="__('notifications::cp.back_to_index')"
                icon="arrow-left"
                :url="indexUrl"
            >
                <Button
                    :href="indexUrl"
                    :text="__('notifications::cp.back_to_index')"
                    icon="arrow-left"
                />
            </CommandPaletteItem>
        </Header>

        <Panel :heading="__('notifications::cp.detail_title')">
            <Card>
                <Table>
                    <TableRows>
                        <TableRow v-for="[key, value] in fields" :key="key">
                            <TableCell>
                                <Text variant="strong" :text="__('notifications::cp.' + key)" />
                            </TableCell>
                            <TableCell>
                                <Text :text="value ?? '—'" />
                            </TableCell>
                        </TableRow>
                    </TableRows>
                </Table>
            </Card>
        </Panel>

        <Panel :heading="__('notifications::cp.detail_data')">
            <Card>
                <Text as="pre" variant="code" class="block overflow-x-auto" :text="payload" />
            </Card>
        </Panel>
    </div>
</template>
