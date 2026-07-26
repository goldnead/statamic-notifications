<?php

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\IdentityContracts\Identity;
use Goldnead\Notifications\Facades\Notifications;
use Statamic\Facades\User;

/** A super user passes every permission check; the gate itself is tested separately. */
function permitted(): void
{
    $user = User::make()->email('cp@example.com')->makeSuper();
    $user->save();

    test()->actingAs($user);
}

it('refuses access without the view permission', function (): void {
    Notifications::notify(Identity::user(1), 'community.mention');

    $this->get('/cp/notifications')->assertForbidden();
});

it('lists notifications for a permitted user', function (): void {
    permitted();

    Notifications::notify(Identity::user(1, 'member@example.com'), 'community.mention', ['message' => 'hallo']);

    $this->get('/cp/notifications')
        ->assertOk()
        ->assertSee('community.mention')
        ->assertSee('member@example.com');
});

it('filters by type and by unread', function (): void {
    permitted();

    $read = Notifications::notify(Identity::user(1), 'community.mention');
    Notifications::markRead($read);
    Notifications::notify(Identity::user(1), 'community.reply');

    $this->get('/cp/notifications?type=community.reply')
        ->assertOk()
        ->assertSee('community.reply')
        ->assertDontSee('community.mention');

    $this->get('/cp/notifications?unread=1')
        ->assertOk()
        ->assertSee('community.reply');
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
        ->assertSee('mention:1');
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
