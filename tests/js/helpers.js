import { vi } from 'vitest';
import { router } from '@statamic/cms/inertia';

/**
 * Shared plumbing for the page tests.
 *
 * Adapted from the sibling addons' helpers. The form-submission helpers they
 * carry (`reject`, `summary`) are deliberately absent: these two screens are a
 * read-only inspector, so there is no request whose 422 a test would have to
 * play back. What is left is what a read-only page needs — recording the
 * router, pressing a button and reading what the user can see.
 */

/**
 * Replaces the router's verbs with recorders and returns the recorded calls.
 *
 * `router` is the object the `@statamic/cms/inertia` shim destructures out of
 * `__STATAMIC__`, so the page and the test hold the same reference and
 * replacing a method here replaces the one the page calls.
 */
export function captureRouter() {
    const calls = [];

    for (const verb of ['post', 'patch', 'put', 'delete', 'get', 'reload']) {
        if (! (verb in router)) {
            router[verb] = () => {};
        }

        vi.spyOn(router, verb).mockImplementation((...args) => {
            // post/patch take (url, data, options); delete/get take (url, options);
            // reload takes (options) alone, which is why the url cannot simply be
            // assumed to be the first argument.
            const url = typeof args[0] === 'string' ? args[0] : null;

            const options = args
                .slice(url === null ? 0 : 1)
                .find((argument) => argument
                    && typeof argument === 'object'
                    && ('onError' in argument
                        || 'onSuccess' in argument
                        || 'onFinish' in argument
                        || 'preserveScroll' in argument));

            calls.push({ verb, url, options: options ?? {} });
        });
    }

    return calls;
}

/**
 * Presses the button carrying this label, the way a person would.
 *
 * The stubs render every scalar attribute, so a button is located by what the
 * template labelled it with rather than by its position, and the handler that
 * is invoked is the one the template actually bound to `@click`.
 */
export function press(wrapper, label) {
    const button = wrapper.findAllComponents({ name: 'Button' })
        .find((candidate) => candidate.attributes('data-attr-text') === label);

    if (! button) {
        throw new Error(`No button labelled "${label}" is rendered.`);
    }

    button.vm.$attrs.onClick?.();

    return button;
}

/**
 * Everything the page is showing the user, as one string.
 *
 * Text-carrying props reach the DOM through the stub's mirrored attributes as
 * well as through its text content, so "is this visible anywhere" has to look
 * at both.
 */
export function visibleText(wrapper) {
    const attributes = wrapper.findAll('[data-attr-text]')
        .map((node) => node.attributes('data-attr-text'))
        .join(' | ');

    return `${wrapper.text()} | ${attributes}`;
}
