<?php

use Goldnead\IdentityContracts\Identity;
use Goldnead\Notifications\Channels\ChannelRegistry;
use Goldnead\Notifications\Contracts\Channel;
use Goldnead\Notifications\Contracts\DeferredChannel;
use Goldnead\Notifications\Contracts\DeferredRecipientResolver;
use Goldnead\Notifications\Facades\Notifications;
use Goldnead\Notifications\Models\NotificationDelivery;
use Goldnead\Notifications\Models\NotificationItem;
use Goldnead\Notifications\Sending\DeferredChannelOutcome;
use Goldnead\Notifications\Sending\DeferredDelivery;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * N1 (Sweep 17.11.2026): a row that ends `failed` must not leave the cron green and the log empty.
 */
function bindReachableResolver(): void
{
    app()->instance(DeferredRecipientResolver::class, new class implements DeferredRecipientResolver
    {
        public function resolve(NotificationItem $item): ?Identity
        {
            return Identity::user((int) $item->user_id, 'live@example.com');
        }
    });
    app()->forgetInstance(DeferredDelivery::class);
}

/** The only channel a deferred row of this type uses fails with a transport error on every attempt. */
function breakTheChannel(): void
{
    config(['notifications.deferred.max_attempts' => 1]);
    app(ChannelRegistry::class)->register('in_app', fn () => new class implements Channel, DeferredChannel
    {
        public function send(NotificationItem $item, Identity $recipient): void {}

        public function sendDeferred(NotificationItem $item, Identity $recipient): DeferredChannelOutcome
        {
            throw new RuntimeException('transport down');
        }
    });
    app()->forgetInstance(DeferredDelivery::class);
}

it('exits non-zero and logs an error when a row ends failed', function (): void {
    bindReachableResolver();
    Notifications::notifyDeferred(Identity::user(1, 'a@example.com'), 'community.mention');
    breakTheChannel();
    Log::spy();

    $code = Artisan::call('notifications:dispatch-pending');

    expect(NotificationDelivery::query()->where('status', NotificationDelivery::STATUS_FAILED)->count())->toBeGreaterThan(0)
        ->and($code)->toBe(1);
    Log::shouldHaveReceived('error')
        ->withArgs(fn ($message, $context = []) => str_contains((string) $message, 'failed')
            && isset($context['outcome_code']))
        ->atLeast()->once();
});

it('stays green when every row was accepted', function (): void {
    Mail::fake();
    bindReachableResolver();
    Notifications::notifyDeferred(Identity::user(1, 'a@example.com'), 'community.mention');
    Log::spy();

    expect(Artisan::call('notifications:dispatch-pending'))->toBe(0);
    Log::shouldNotHaveReceived('error');
});

it('stays green and quiet while a failing row still has attempts left', function (): void {
    bindReachableResolver();
    Notifications::notifyDeferred(Identity::user(1, 'a@example.com'), 'community.mention');
    breakTheChannel();
    config(['notifications.deferred.max_attempts' => 3]);
    Log::spy();

    expect(Artisan::call('notifications:dispatch-pending'))->toBe(0)
        ->and(NotificationDelivery::query()->where('status', NotificationDelivery::STATUS_RETRY)->count())->toBeGreaterThan(0);
    // report() of the transport exception logs an error of its own; only the dead-delivery line must be absent.
    Log::shouldNotHaveReceived('error', [Mockery::on(fn ($m) => str_contains((string) $m, 'failed for good')), Mockery::any()]);
});
