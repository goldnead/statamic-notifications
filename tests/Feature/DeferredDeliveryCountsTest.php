<?php

use Goldnead\IdentityContracts\Identity;
use Goldnead\Notifications\Contracts\DeferredRecipientResolver;
use Goldnead\Notifications\Facades\Notifications;
use Goldnead\Notifications\Models\NotificationDelivery;
use Goldnead\Notifications\Models\NotificationItem;
use Goldnead\Notifications\Sending\DeferredDelivery;
use Illuminate\Support\Facades\Mail;

/** Resolves every item to a fixed address, or to nobody when told to. */
function bindResolver(bool $reachable): void
{
    app()->instance(DeferredRecipientResolver::class, new class($reachable) implements DeferredRecipientResolver
    {
        public function __construct(protected bool $reachable) {}

        public function resolve(NotificationItem $item): ?Identity
        {
            return $this->reachable ? Identity::user((int) $item->user_id, 'live@example.com') : null;
        }
    });
    app()->forgetInstance(DeferredDelivery::class);
}

it('counts every row a dispatch run handled as claimed', function (): void {
    Mail::fake();
    bindResolver(true);

    Notifications::notifyDeferred(Identity::user(1, 'a@example.com'), 'community.mention');

    $rows = NotificationDelivery::query()->count();
    expect($rows)->toBeGreaterThan(0);

    $counts = app(DeferredDelivery::class)->dispatchPending(100);

    expect($counts['claimed'])->toBe($rows)
        ->and($counts['accepted'])->toBe($rows);
});

it('counts a cancelled row as claimed too', function (): void {
    Mail::fake();
    bindResolver(false);

    Notifications::notifyDeferred(Identity::user(1, 'a@example.com'), 'community.mention');
    $rows = NotificationDelivery::query()->count();

    $counts = app(DeferredDelivery::class)->dispatchPending(100);

    expect($counts['claimed'])->toBe($rows)
        ->and($counts['cancelled'])->toBe($rows)
        ->and($counts['accepted'])->toBe(0);
});

it('does not count a run that finds nothing due', function (): void {
    expect(app(DeferredDelivery::class)->dispatchPending(100)['claimed'])->toBe(0);
});
