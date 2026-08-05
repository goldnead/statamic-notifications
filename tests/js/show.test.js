import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import Show from '../../resources/js/pages/Show.vue';
import { visibleText } from './helpers.js';

const notification = {
    type: 'community.mention',
    created_at: '2026-08-04 09:15:22',
    recipient: 'member@example.com',
    message: 'Bea hat dich erwähnt',
    link: '/community/threads/7',
    actor: 'Bea',
    dedupe_key: 'mention:1',
    read_at: null,
    digested_at: '2026-08-04 18:00:00',
    data: { thread_id: 7, url: 'https://example.com/a/b' },
};

function page(overrides = {}) {
    return mount(Show, {
        props: {
            notification: { ...notification, ...overrides },
            indexUrl: '/cp/notifications',
        },
    });
}

/** The labels the detail table renders, in the order it renders them. */
function labels(wrapper) {
    return wrapper.findAllComponents({ name: 'Text' })
        .filter((text) => text.attributes('data-attr-variant') === 'strong')
        .map((text) => text.attributes('data-attr-text'));
}

/** The value cell sitting next to a given label. */
function valueFor(wrapper, label) {
    const row = wrapper.findAllComponents({ name: 'TableRow' })
        .find((candidate) => candidate.text().includes(label));

    if (! row) {
        throw new Error(`No row labelled "${label}" is rendered.`);
    }

    return row.findAllComponents({ name: 'Text' })
        .find((text) => text.attributes('data-attr-variant') !== 'strong')
        .attributes('data-attr-text');
}

describe('a single notification', () => {
    it('shows every field the inspector promises, in a fixed order', () => {
        expect(labels(page())).toEqual([
            'notifications::cp.field_created',
            'notifications::cp.col_recipient',
            'notifications::cp.field_message',
            'notifications::cp.field_link',
            'notifications::cp.field_actor',
            'notifications::cp.field_dedupe_key',
            'notifications::cp.col_read',
            'notifications::cp.col_digested',
        ]);
    });

    it('puts the type in the page header', () => {
        expect(page().findComponent({ name: 'Header' }).attributes('data-attr-title'))
            .toBe('community.mention');
    });

    it('leads back to the index', () => {
        const back = page().findAllComponents({ name: 'Button' })
            .find((button) => button.attributes('data-attr-href') === '/cp/notifications');

        expect(back).toBeTruthy();
        expect(back.attributes('data-attr-text')).toBe('notifications::cp.back_to_index');
    });

    it('offers that way back in the command palette too', () => {
        // Every core page-level action is reachable from the palette; one that
        // is not makes an addon screen feel inert next to core.
        const item = page().findComponent({ name: 'CommandPaletteItem' });

        expect(item.exists()).toBe(true);
        expect(item.attributes('data-attr-url')).toBe('/cp/notifications');
        expect(item.attributes('data-attr-category')).toBe('Actions');
    });

    it('reads the values out beside their labels', () => {
        const wrapper = page();

        expect(valueFor(wrapper, 'notifications::cp.field_created')).toBe('2026-08-04 09:15:22');
        expect(valueFor(wrapper, 'notifications::cp.col_recipient')).toBe('member@example.com');
        expect(valueFor(wrapper, 'notifications::cp.field_message')).toBe('Bea hat dich erwähnt');
        expect(valueFor(wrapper, 'notifications::cp.field_dedupe_key')).toBe('mention:1');
    });

    it('says "unread" rather than leaving the read row empty', () => {
        expect(valueFor(page(), 'notifications::cp.col_read')).toBe('notifications::cp.unread');
        expect(valueFor(page({ read_at: '2026-08-04 10:00:00' }), 'notifications::cp.col_read'))
            .toBe('2026-08-04 10:00:00');
    });

    it('shows an em dash where the database holds nothing', () => {
        const wrapper = page({ link: null, actor: null, digested_at: null });

        expect(valueFor(wrapper, 'notifications::cp.field_link')).toBe('—');
        expect(valueFor(wrapper, 'notifications::cp.field_actor')).toBe('—');
        expect(valueFor(wrapper, 'notifications::cp.col_digested')).toBe('—');
    });

    it('pretty-prints the payload without escaping slashes or umlauts', () => {
        // The Blade page used PHP's JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|
        // JSON_UNESCAPED_UNICODE; JSON.stringify escapes neither, so the text
        // on screen is the same text as before the port.
        const payload = page({ data: { grüße: 'a/b' } })
            .findAllComponents({ name: 'Text' })
            .find((text) => text.attributes('data-attr-as') === 'pre')
            .attributes('data-attr-text');

        expect(payload).toBe('{\n    "grüße": "a/b"\n}');
    });

    it('survives a notification with no payload at all', () => {
        const payload = page({ data: null })
            .findAllComponents({ name: 'Text' })
            .find((text) => text.attributes('data-attr-as') === 'pre')
            .attributes('data-attr-text');

        expect(payload).toBe('null');
    });

    it('shows a producer\'s mustache as text, not as a template', () => {
        // The regression the Blade page needed a hand-written rule for: it
        // compiled into a Vue template, so `{{ 2 + 2 }}` in a message was a
        // page-breaking compile error. A prop is data and is never compiled.
        const wrapper = page({ message: 'total {{ 2 + 2 }}' });

        expect(valueFor(wrapper, 'notifications::cp.field_message')).toBe('total {{ 2 + 2 }}');
        expect(visibleText(wrapper)).toContain('total {{ 2 + 2 }}');
    });
});
