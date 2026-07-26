<?php

use Goldnead\IdentityContracts\Identity;
use Goldnead\Notifications\Digest\DigestBuilder;
use Goldnead\Notifications\Facades\Notifications;
use Goldnead\Notifications\Mail\DigestMail;
use Goldnead\Notifications\Models\NotificationDigestRun;
use Goldnead\Notifications\Models\NotificationItem;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    $this->builder = app(DigestBuilder::class);

    Notifications::registerType('community.reply', function ($type): void {
        $type->label('Antwort')->defaultChannels(['in_app', 'digest']);
    });
});

function notifyAt(string $when, string $message = 'x'): NotificationItem
{
    $item = Notifications::notify(Identity::user(1, 'a@example.com'), 'community.reply', ['message' => $message]);
    $item->forceFill(['created_at' => $when])->save();

    return $item->fresh();
}

it('collects only what falls inside the window', function (): void {
    notifyAt(now()->subDays(2)->toDateTimeString(), 'inside');
    notifyAt(now()->subDays(20)->toDateTimeString(), 'outside');

    $collected = $this->builder->collect(Identity::user(1), 'weekly');

    expect($collected['items'])->toHaveCount(1)
        ->and($collected['items']->first()->message)->toBe('inside');
});

it('uses a shorter window for daily than for weekly', function (): void {
    notifyAt(now()->subDays(3)->toDateTimeString());

    expect($this->builder->collect(Identity::user(1), 'daily')['items'])->toHaveCount(0)
        ->and($this->builder->collect(Identity::user(1), 'weekly')['items'])->toHaveCount(1);
});

it('stamps collected items so the next run cannot pick them up again', function (): void {
    notifyAt(now()->subDay()->toDateTimeString());

    $collected = $this->builder->collect(Identity::user(1), 'weekly');
    $this->builder->markSent(Identity::user(1), 'weekly', $collected);

    expect(NotificationItem::first()->digested_at)->not->toBeNull()
        ->and($this->builder->collect(Identity::user(1), 'weekly')['items'])->toHaveCount(0);
});

it('refuses a second send for a window it already sent', function (): void {
    notifyAt(now()->subDay()->toDateTimeString());

    $collected = $this->builder->collect(Identity::user(1), 'weekly');

    expect($this->builder->markSent(Identity::user(1), 'weekly', $collected))->not->toBeNull()
        ->and($this->builder->markSent(Identity::user(1), 'weekly', $collected))->toBeNull()
        ->and(NotificationDigestRun::count())->toBe(1);
});

it('does not repeat an unread item every run — the bug this replaces', function (): void {
    Mail::fake();
    notifyAt(now()->subDay()->toDateTimeString(), 'nur einmal');

    // Nobody ever marks it read. The old community digest resent it weekly for
    // exactly that reason.
    $this->artisan('notifications:send-digests', ['--frequency' => 'weekly'])->assertSuccessful();
    $this->artisan('notifications:send-digests', ['--frequency' => 'weekly'])->assertSuccessful();

    Mail::assertSentCount(1);
    expect(NotificationItem::first()->read_at)->toBeNull();
});

it('sends nothing when there is nothing in the window', function (): void {
    Mail::fake();
    notifyAt(now()->subDays(30)->toDateTimeString());

    $this->artisan('notifications:send-digests', ['--frequency' => 'weekly'])->assertSuccessful();

    Mail::assertNothingSent();
});

it('sends a digest mail to a recipient with pending items', function (): void {
    Mail::fake();
    notifyAt(now()->subDay()->toDateTimeString());

    $this->artisan('notifications:send-digests', ['--frequency' => 'weekly'])->assertSuccessful();

    Mail::assertSent(DigestMail::class);
});

it('does not catch a weekly recipient in the daily run', function (): void {
    Mail::fake();
    notifyAt(now()->subHours(2)->toDateTimeString());

    $this->artisan('notifications:send-digests', ['--frequency' => 'daily'])->assertSuccessful();

    Mail::assertNothingSent();
});

it('changes nothing on a dry run', function (): void {
    Mail::fake();
    notifyAt(now()->subDay()->toDateTimeString());

    $this->artisan('notifications:send-digests', ['--frequency' => 'weekly', '--dry-run' => true])->assertSuccessful();

    Mail::assertNothingSent();
    expect(NotificationDigestRun::count())->toBe(0)
        ->and(NotificationItem::first()->digested_at)->toBeNull();
});

it('rejects an unknown frequency', function (): void {
    $this->artisan('notifications:send-digests', ['--frequency' => 'hourly'])->assertFailed();
});
