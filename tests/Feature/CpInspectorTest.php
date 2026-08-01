<?php

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\IdentityContracts\Identity;
use Goldnead\Notifications\Facades\Notifications;
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

it('renders the index on core listing components', function (): void {
    permitted();

    $response = $this->get('/cp/notifications')->assertOk();

    // The screen is core's listing, not a hand-built table. If this ever turns
    // back into markup of our own, these go with it.
    $response->assertSee('<ui-listing', false);
    $response->assertSee('cp/notifications/listing', false);
    $response->assertSee('preferences-prefix="notifications.listing"', false);
    $response->assertDontSee('<table', false);
    $response->assertDontSee('class="card', false);
});

it('offers the three filters to the listing', function (): void {
    permitted();

    Notifications::notify(Identity::user(1), 'community.mention');

    $response = $this->get('/cp/notifications')->assertOk();

    foreach (['notification_type', 'notification_read_state', 'notification_recipient'] as $handle) {
        $response->assertSee($handle, false);
    }
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

    $item = Notifications::notify(Identity::user(1), 'community.mention', [
        'message' => 'Bea hat dich erwähnt',
        'dedupe_key' => 'mention:1',
    ]);

    $this->get('/cp/notifications/'.$item->id)
        ->assertOk()
        ->assertSee('Bea hat dich erwähnt')
        ->assertSee('mention:1')
        ->assertSee('<ui-panel', false)
        ->assertDontSee('class="card', false);
});

it('does not let a notification body become a Vue expression', function (): void {
    permitted();

    // The CP compiles this page's Blade into a Vue template, so a mustache in
    // producer-supplied text is a page-breaking compile error unless the value
    // stays inside a static attribute. Nothing about the screen looks wrong
    // when this regresses — it just stops rendering.
    $item = Notifications::notify(Identity::user(1), 'community.mention', [
        'message' => 'total {{ 2 + 2 }}',
    ]);

    $this->get('/cp/notifications/'.$item->id)
        ->assertOk()
        ->assertSee('text="total {{ 2 + 2 }}"', false);
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
