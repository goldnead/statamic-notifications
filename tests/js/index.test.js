import { describe, it, expect, beforeEach, vi } from 'vitest';
import { defineComponent } from 'vue';
import { mount } from '@vue/test-utils';
import Index from '../../resources/js/pages/Index.vue';
import { captureRouter } from './helpers.js';

/**
 * The index screen is core's <Listing> plus five cell templates. What is worth
 * pinning is therefore what the page hands the listing (everything core needs
 * to own search, filters, saved views and pagination) and what each cell puts
 * on screen for a given row.
 */

const columns = [
    { field: 'created_at', label: 'Created', visible: true },
    { field: 'type', label: 'Type', visible: true },
    { field: 'recipient', label: 'Recipient', visible: true },
    { field: 'read_at', label: 'Read', visible: true },
    { field: 'digested_at', label: 'Digested', visible: true },
];

const filters = [
    { handle: 'notification_type' },
    { handle: 'notification_read_state' },
    { handle: 'notification_recipient' },
];

function page() {
    return mount(Index, {
        props: {
            columns,
            filters,
            listingUrl: '/cp/notifications/listing',
            preferencesPrefix: 'notifications.listing',
        },
    });
}

function listing(wrapper) {
    return wrapper.findComponent({ name: 'Listing' });
}

/**
 * Renders one of the listing's `#cell-*` templates for a given row.
 *
 * The generic stubs render the default slot only, so a named scoped slot has
 * to be invoked by hand and mounted. That is still the template the page
 * actually declared, not a copy of it.
 */
function cell(wrapper, name, { row = {}, value = null } = {}) {
    const slot = listing(wrapper).vm.$slots[`cell-${name}`];

    expect(slot, `The page declares no #cell-${name} template.`).toBeTruthy();

    // The slot renders a fragment, so the assertion target is the stub inside
    // it rather than the mounted root.
    return mount(defineComponent({ render: () => slot({ row, value }) })).find('[data-stub]');
}

beforeEach(() => {
    vi.restoreAllMocks();
});

describe('the notification index', () => {
    it('hands the listing everything core needs to own the screen', () => {
        const attributes = listing(page()).attributes();

        // Server mode: without the url the listing would filter and sort a
        // static array in the browser, and would page through nothing.
        expect(attributes['data-attr-url']).toBe('/cp/notifications/listing');
        // Without a preferences prefix there are no saved views and no
        // persisted column choices (ui-vocabulary §3.2).
        expect(attributes['data-attr-preferences-prefix']).toBe('notifications.listing');
        expect(attributes['data-attr-sort-column']).toBe('created_at');
        expect(attributes['data-attr-sort-direction']).toBe('desc');
        expect(attributes['data-attr-push-query']).toBeDefined();
    });

    it('passes the columns and the three filters through untouched', () => {
        // The stubs declare no props, so object-valued bindings arrive as
        // attrs — that is also the only place the real component would read
        // them from.
        const props = listing(page()).vm.$attrs;

        expect(props.columns).toEqual(columns);
        expect(props.filters.map((filter) => filter.handle)).toEqual([
            'notification_type',
            'notification_read_state',
            'notification_recipient',
        ]);
    });

    it('offers no bulk actions, because the inspector never writes', () => {
        // No action-url means no checkboxes and no bulk toolbar. That is the
        // read-only promise expressed in the one place the listing reads it.
        expect(listing(page()).attributes()['data-attr-action-url']).toBeUndefined();
    });

    it('makes the timestamp the way into a single notification', () => {
        const rendered = cell(page(), 'created_at', {
            row: { url: '/cp/notifications/42' },
            value: '2026-08-04 09:15',
        });

        expect(rendered.attributes('data-attr-href')).toBe('/cp/notifications/42');
        expect(rendered.attributes('data-attr-text')).toBe('2026-08-04 09:15');
    });

    it('says "unread" instead of leaving the read column blank', () => {
        const unread = cell(page(), 'read_at', { row: { is_read: false }, value: null });

        expect(unread.attributes('data-stub')).toBe('Badge');
        expect(unread.attributes('data-attr-color')).toBe('amber');
        expect(unread.attributes('data-attr-text')).toBe('notifications::cp.unread');

        const read = cell(page(), 'read_at', { row: { is_read: true }, value: '2026-08-04 10:00' });

        expect(read.attributes('data-stub')).toBe('Text');
        expect(read.attributes('data-attr-text')).toBe('2026-08-04 10:00');
    });

    it('shows an em dash where the database holds nothing', () => {
        expect(cell(page(), 'recipient').attributes('data-attr-text')).toBe('—');
        expect(cell(page(), 'digested_at').attributes('data-attr-text')).toBe('—');

        expect(cell(page(), 'recipient', { value: 'a@example.com' }).attributes('data-attr-text'))
            .toBe('a@example.com');
    });

    it('renders a notification type as code, so a dotted handle reads as one', () => {
        const rendered = cell(page(), 'type', { value: 'community.mention' });

        expect(rendered.attributes('data-attr-variant')).toBe('code');
        expect(rendered.attributes('data-attr-text')).toBe('community.mention');
    });

    it('shows a message a producer wrote as text, never as a template', () => {
        // The Blade page it replaces had to keep every database value inside a
        // static attribute: core compiled the yielded Blade into a Vue template,
        // so a mustache in producer text took the whole screen down. Props are
        // data and are never compiled — this asserts the mustache survives as
        // characters.
        const rendered = cell(page(), 'recipient', { value: 'total {{ 2 + 2 }}' });

        expect(rendered.attributes('data-attr-text')).toBe('total {{ 2 + 2 }}');
    });

    it('reloads the page when the listing asks to refresh', () => {
        const calls = captureRouter();
        const wrapper = page();

        listing(wrapper).vm.$emit('refreshing');

        expect(calls).toHaveLength(1);
        expect(calls[0].verb).toBe('reload');
        expect(calls[0].options.preserveScroll).toBe(true);
    });
});
