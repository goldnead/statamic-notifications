<?php

use Goldnead\IdentityContracts\Identity;
use Goldnead\Notifications\Facades\Notifications;
use Goldnead\Notifications\Models\NotificationItem;

it('persists a notification against the recipient join keys', function (): void {
    $item = Notifications::notify(Identity::user(42, 'a@example.com'), 'community.mention', [
        'actor' => Identity::user(7, 'b@example.com', 'Bea'),
        'message' => 'Bea hat dich erwähnt.',
        'link' => '/account/community/posts/9',
    ]);

    expect($item)->not->toBeNull()
        ->and($item->type)->toBe('community.mention')
        ->and($item->user_id)->toBe('42')
        ->and($item->actor_name)->toBe('Bea')
        ->and($item->message)->toBe('Bea hat dich erwähnt.')
        ->and($item->read_at)->toBeNull()
        ->and($item->digested_at)->toBeNull();
});

it('refuses to notify somebody with no durable join key', function (): void {
    // An anonymous visitor could never be shown the notification again.
    expect(Notifications::notify(Identity::anonymous('anon-1'), 'community.mention'))->toBeNull()
        ->and(NotificationItem::count())->toBe(0);
});

it('records the same fact only once', function (): void {
    $recipient = Identity::user(1);

    $first = Notifications::notify($recipient, 'community.reply', ['dedupe_key' => 'reply:99']);
    $second = Notifications::notify($recipient, 'community.reply', ['dedupe_key' => 'reply:99']);

    expect(NotificationItem::count())->toBe(1)
        ->and($second->id)->toBe($first->id);
});

it('treats a notification without a dedupe key as a new fact each time', function (): void {
    Notifications::notify(Identity::user(1), 'community.reaction');
    Notifications::notify(Identity::user(1), 'community.reaction');

    expect(NotificationItem::count())->toBe(2);
});

it('scopes the dedupe key per recipient when notifying many', function (): void {
    Notifications::notifyMany(
        [Identity::user(1), Identity::user(2), Identity::user(3)],
        'community.post',
        ['dedupe_key' => 'post:5'],
    );

    // One shared fact, three recipients, three notifications — and a repeat
    // run adds none.
    expect(NotificationItem::count())->toBe(3);

    Notifications::notifyMany(
        [Identity::user(1), Identity::user(2), Identity::user(3)],
        'community.post',
        ['dedupe_key' => 'post:5'],
    );

    expect(NotificationItem::count())->toBe(3);
});

it('marks a single notification read', function (): void {
    $item = Notifications::notify(Identity::user(1), 'community.mention');

    Notifications::markRead($item);

    expect($item->fresh()->read_at)->not->toBeNull();
});

it('marks everything read for one recipient only', function (): void {
    Notifications::notify(Identity::user(1), 'community.mention');
    Notifications::notify(Identity::user(1), 'community.reply');
    Notifications::notify(Identity::user(2), 'community.mention');

    $marked = Notifications::markAllRead(Identity::user(1));

    expect($marked)->toBe(2)
        ->and(Notifications::unreadCount(Identity::user(1)))->toBe(0)
        ->and(Notifications::unreadCount(Identity::user(2)))->toBe(1);
});

it('lists newest first and honours the limit', function (): void {
    foreach (range(1, 5) as $i) {
        Notifications::notify(Identity::user(1), 'community.reply', ['message' => 'nr '.$i]);
    }

    $items = Notifications::forRecipient(Identity::user(1), 3);

    expect($items)->toHaveCount(3)
        ->and($items->first()->message)->toBe('nr 5');
});

it('never lets a store failure escape into the caller', function (): void {
    Schema::drop('notification_items');

    expect(Notifications::notify(Identity::user(1), 'community.mention'))->toBeNull();
});

it('writes nothing when the addon is disabled', function (): void {
    config()->set('notifications.enabled', false);

    expect(Notifications::notify(Identity::user(1), 'community.mention'))->toBeNull()
        ->and(NotificationItem::count())->toBe(0);
});
