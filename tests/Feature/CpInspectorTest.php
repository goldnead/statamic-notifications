<?php

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\IdentityContracts\Identity;
use Goldnead\Notifications\Facades\Notifications;
use Inertia\Testing\AssertableInertia as Assert;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/** A super user passes every permission check; the gate itself is tested separately. */
function permitted(): void
{
    $user = User::make()->email('cp@example.com')->makeSuper();
    $user->save();

    test()->actingAs($user);
}

/**
 * A signed-in user who may open the CP but holds no notification permission —
 * the case the gate on each controller action actually has to catch.
 */
function unpermitted(): void
{
    Role::make('cp-only')->addPermission('access cp')->save();

    $user = User::make()->email('nobody@example.com')->assignRole('cp-only');
    $user->save();

    test()->actingAs($user);
}

/** The listing sends its filters as base64-encoded JSON (ui-vocabulary §3.3). */
function filterQuery(array $filters): string
{
    return base64_encode(json_encode($filters));
}

it('sends an anonymous visitor to the login screen', function (): void {
    Notifications::notify(Identity::user(1), 'community.mention');

    $this->get('/cp/notifications')->assertRedirect('/cp/auth/login');
});

it('refuses every action without the view permission', function (string $path): void {
    unpermitted();

    $item = Notifications::notify(Identity::user(1), 'community.mention');

    // Core turns an AuthorizationException inside the CP into a redirect to
    // /cp rather than a bare 403, so that — not the status code — is what a
    // denied operator actually sees.
    $this->get(str_replace('{id}', (string) $item->id, $path))->assertRedirect('/cp');
    expect(User::current()->can('view notifications'))->toBeFalse();
})->with([
    '/cp/notifications',
    '/cp/notifications/listing',
    '/cp/notifications/{id}',
]);

it('renders the index as the registered Inertia page', function (): void {
    permitted();

    // The component name is a contract with resources/js/cp.js, where the same
    // string is handed to Statamic.$inertia.register(). A rename on one side
    // and not the other renders a blank page with no error.
    $this->get('/cp/notifications')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('notifications::Index')
            ->where('listingUrl', cp_route('notifications.listing'))
            ->where('preferencesPrefix', 'notifications.listing')
            ->has('columns', 5)
            ->has('filters')
        );
});

it('hands the index every column, in the order the screen shows them', function (): void {
    permitted();

    $columns = collect($this->get('/cp/notifications')->viewData('page')['props']['columns'])
        ->pluck('field')
        ->all();

    expect($columns)->toBe(['created_at', 'type', 'recipient', 'read_at', 'digested_at']);
});

it('offers the three filters to the listing', function (): void {
    permitted();

    Notifications::notify(Identity::user(1), 'community.mention');

    $filters = collect($this->get('/cp/notifications')->viewData('page')['props']['filters'])
        ->pluck('handle')
        ->all();

    expect($filters)->toEqualCanonicalizing([
        'notification_type',
        'notification_read_state',
        'notification_recipient',
    ]);
});

it('gives the Control Panel Javascript the addon\'s own translation keys', function (): void {
    permitted();

    // Both Vue pages call `__('notifications::cp.…')`. Those keys only resolve
    // in the browser because the addon registers its lang namespace on the
    // translator, which Statamic flattens into the CP's JS translations. Drop
    // the namespace registration and the screen renders raw keys — visibly
    // broken, and nothing else fails.
    $translations = app('translator')->toJson();

    expect($translations)->toHaveKey('notifications::cp.title');
    expect($translations['notifications::cp.unread'])->toBe('unread');
});

it('answers the listing endpoint in the shape the listing expects', function (): void {
    permitted();

    Notifications::notify(Identity::user(1, 'member@example.com'), 'community.mention', ['message' => 'hallo']);

    $this->getJson('/cp/notifications/listing')
        ->assertOk()
        ->assertJsonPath('data.0.type', 'community.mention')
        ->assertJsonPath('data.0.recipient', 'member@example.com')
        ->assertJsonPath('data.0.is_read', false)
        ->assertJsonPath('meta.total', 1)
        ->assertJsonStructure([
            'data' => [['id', 'created_at', 'type', 'recipient', 'read_at', 'digested_at', 'is_read', 'url']],
            'meta' => ['columns', 'activeFilterBadges', 'current_page', 'last_page', 'per_page', 'from', 'to', 'total'],
        ]);
});

it('returns every column on every response, hidden ones included', function (): void {
    permitted();

    Notifications::notify(Identity::user(1), 'community.mention');

    // The listing overwrites its local columns from meta.columns each time, so
    // a column the user hid must come back marked hidden, not go missing.
    $columns = collect($this->getJson('/cp/notifications/listing?columns=type,created_at')
        ->assertOk()
        ->json('meta.columns'))
        ->keyBy('field');

    expect($columns->keys()->sort()->values()->all())
        ->toBe(['created_at', 'digested_at', 'read_at', 'recipient', 'type']);
    expect($columns['type']['visible'])->toBeTrue();
    expect($columns['digested_at']['visible'])->toBeFalse();
});

it('filters by type', function (): void {
    permitted();

    Notifications::notify(Identity::user(1), 'community.mention');
    Notifications::notify(Identity::user(1), 'community.reply');

    $response = $this->getJson('/cp/notifications/listing?filters='.filterQuery([
        'notification_type' => ['type' => 'community.reply'],
    ]))->assertOk();

    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.type'))->toBe('community.reply');
    expect($response->json('meta.activeFilterBadges.notification_type'))->toContain('community.reply');
});

it('filters by read state', function (): void {
    permitted();

    $read = Notifications::notify(Identity::user(1), 'community.mention');
    Notifications::markRead($read);
    Notifications::notify(Identity::user(1), 'community.reply');

    expect($this->getJson('/cp/notifications/listing?filters='.filterQuery([
        'notification_read_state' => ['state' => 'unread'],
    ]))->json('data.0.type'))->toBe('community.reply');

    expect($this->getJson('/cp/notifications/listing?filters='.filterQuery([
        'notification_read_state' => ['state' => 'read'],
    ]))->json('data.0.type'))->toBe('community.mention');
});

it('filters by recipient on whichever join key was written', function (): void {
    permitted();

    Notifications::notify(Identity::user(7, 'seven@example.com'), 'community.mention');
    Notifications::notify(Identity::user(8, 'eight@example.com'), 'community.reply');

    foreach (['seven@example.com', '7'] as $needle) {
        $response = $this->getJson('/cp/notifications/listing?filters='.filterQuery([
            'notification_recipient' => ['recipient' => $needle],
        ]))->assertOk();

        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data.0.type'))->toBe('community.mention');
    }
});

it('combines a filter with the search term', function (): void {
    permitted();

    Notifications::notify(Identity::user(1, 'a@example.com'), 'community.mention', ['message' => 'needle']);
    Notifications::notify(Identity::user(2, 'b@example.com'), 'community.mention', ['message' => 'haystack']);
    Notifications::notify(Identity::user(3, 'c@example.com'), 'community.reply', ['message' => 'needle']);

    $response = $this->getJson('/cp/notifications/listing?search=needle&filters='.filterQuery([
        'notification_type' => ['type' => 'community.mention'],
    ]))->assertOk();

    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.recipient'))->toBe('a@example.com');
});

it('sorts only by columns it offers, and falls back rather than trusting input', function (): void {
    permitted();

    Notifications::notify(Identity::user(1), 'b.second');
    Notifications::notify(Identity::user(2), 'a.first');

    expect($this->getJson('/cp/notifications/listing?sort=type&order=asc')->json('data.0.type'))
        ->toBe('a.first');
    expect($this->getJson('/cp/notifications/listing?sort=type&order=desc')->json('data.0.type'))
        ->toBe('b.second');

    // An unknown or hostile sort key must not reach the query builder.
    $this->getJson('/cp/notifications/listing?sort=message%29+--&order=asc')
        ->assertOk()
        ->assertJsonPath('meta.total', 2);
});

it('paginates', function (): void {
    permitted();

    foreach (range(1, 5) as $i) {
        Notifications::notify(Identity::user($i), 'community.mention');
    }

    $first = $this->getJson('/cp/notifications/listing?perPage=2')->assertOk();

    expect($first->json('data'))->toHaveCount(2);
    expect($first->json('meta'))
        ->toMatchArray(['current_page' => 1, 'last_page' => 3, 'per_page' => 2, 'total' => 5]);

    expect($this->getJson('/cp/notifications/listing?perPage=2&page=3')->json('data'))->toHaveCount(1);
});

it('shows a single notification', function (): void {
    permitted();

    $item = Notifications::notify(Identity::user(1, 'member@example.com'), 'community.mention', [
        'message' => 'Bea hat dich erwähnt',
        'dedupe_key' => 'mention:1',
    ]);

    $this->get('/cp/notifications/'.$item->id)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('notifications::Show')
            ->where('indexUrl', cp_route('notifications.index'))
            ->where('notification.type', 'community.mention')
            ->where('notification.recipient', 'member@example.com')
            ->where('notification.message', 'Bea hat dich erwähnt')
            ->where('notification.dedupe_key', 'mention:1')
            ->where('notification.read_at', null)
        );
});

it('sends the detail page only the fields it shows', function (): void {
    permitted();

    $item = Notifications::notify(Identity::user(1), 'community.mention');

    // The model is not handed over wholesale. `brand_id` and the recipient /
    // actor type discriminators are internal, and a column a later migration
    // adds must not reach the browser because nobody looked.
    $notification = $this->get('/cp/notifications/'.$item->id)
        ->viewData('page')['props']['notification'];

    expect(array_keys($notification))->toEqualCanonicalizing([
        'type', 'created_at', 'recipient', 'message', 'link',
        'actor', 'dedupe_key', 'read_at', 'digested_at', 'data',
    ]);
});

it('carries a notification body through verbatim, mustaches included', function (): void {
    permitted();

    // While these screens were Blade, core compiled them into a Vue template
    // and a mustache in producer-supplied text was a page-breaking compile
    // error unless the value stayed inside a static attribute. As an Inertia
    // prop the string is data and is never compiled — this pins that it also
    // is not mangled on the way out.
    $item = Notifications::notify(Identity::user(1), 'community.mention', [
        'message' => 'total {{ 2 + 2 }}',
    ]);

    $this->get('/cp/notifications/'.$item->id)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('notification.message', 'total {{ 2 + 2 }}')
        );
});

it('cannot read another brand\'s notification by guessing its id', function (): void {
    permitted();
    $this->enableMultiBrand();
    $brandA = $this->makeBrand('brand-a');
    $brandB = $this->makeBrand('brand-b');

    $inA = BrandContext::runFor($brandA, fn () => Notifications::notify(Identity::user(1), 'community.mention'));

    BrandContext::setCurrent($brandB);

    $this->get('/cp/notifications/'.$inA->id)->assertNotFound();
});

it('keeps the listing inside the current brand', function (): void {
    permitted();
    $this->enableMultiBrand();
    $brandA = $this->makeBrand('brand-a');
    $brandB = $this->makeBrand('brand-b');

    BrandContext::runFor($brandA, fn () => Notifications::notify(Identity::user(1), 'a.only'));
    BrandContext::runFor($brandB, fn () => Notifications::notify(Identity::user(1), 'b.only'));

    BrandContext::setCurrent($brandB);

    $response = $this->getJson('/cp/notifications/listing')->assertOk();

    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.type'))->toBe('b.only');
});
