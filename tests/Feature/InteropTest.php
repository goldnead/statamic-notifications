<?php

use Goldnead\IdentityContracts\Identity;
use Goldnead\Notifications\Events\NotificationReceived;
use Goldnead\Notifications\Facades\Notifications;
use Goldnead\Notifications\Models\NotificationItem;
use Goldnead\Notifications\Tests\Fixtures\LeadAssignedLaravelNotification;
use Goldnead\Notifications\Tests\Fixtures\MailOnlyLaravelNotification;
use Goldnead\Notifications\Tests\Fixtures\NotifiableUser;
use Illuminate\Support\Facades\Event;

it('routes a Laravel notification into the persisted store', function (): void {
    $user = new NotifiableUser(['id' => 12, 'email' => 'a@example.com']);

    $user->notify(new LeadAssignedLaravelNotification);

    $item = NotificationItem::first();

    expect(NotificationItem::count())->toBe(1)
        ->and($item->type)->toBe('crm.lead_assigned')
        ->and($item->user_id)->toBe('12')
        ->and($item->message)->toBe('Dir wurde ein Lead zugewiesen.');
});

it('ignores a Laravel notification that does not target this channel', function (): void {
    $user = new NotifiableUser(['id' => 12, 'email' => 'a@example.com']);

    $user->notify(new MailOnlyLaravelNotification);

    expect(NotificationItem::count())->toBe(0);
});

it('broadcasts a content-free refresh signal when realtime is on', function (): void {
    Event::fake([NotificationReceived::class]);
    config()->set('notifications.realtime.enabled', true);

    Notifications::registerType('community.mention', fn ($type) => $type->defaultChannels(['in_app']));
    Notifications::notify(Identity::user(5), 'community.mention', ['message' => 'geheim']);

    Event::assertDispatched(NotificationReceived::class, function (NotificationReceived $event): bool {
        // The payload must never carry the content — a socket subscriber would
        // otherwise see more than the API would have given them.
        return $event->userId === '5'
            && $event->broadcastWith() === ['reason' => 'refresh', 'type' => 'community.mention'];
    });
});

it('stays silent when realtime is off', function (): void {
    Event::fake([NotificationReceived::class]);

    Notifications::registerType('community.mention', fn ($type) => $type->defaultChannels(['in_app']));
    Notifications::notify(Identity::user(5), 'community.mention');

    Event::assertNotDispatched(NotificationReceived::class);
});

it('broadcasts on the recipient private channel', function (): void {
    config()->set('notifications.realtime.channel_prefix', 'members');

    $event = new NotificationReceived('9', 'community.mention');

    expect($event->broadcastOn()->name)->toBe('private-members.9')
        ->and($event->broadcastAs())->toBe('NotificationReceived');
});
