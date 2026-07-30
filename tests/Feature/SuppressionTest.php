<?php

use Goldnead\IdentityContracts\Identity;
use Goldnead\Notifications\Facades\Notifications;
use Goldnead\Notifications\Mail\DigestMail;
use Goldnead\Notifications\Mail\NotificationMail;
use Goldnead\Notifications\Models\NotificationItem;
use Goldnead\Suppression\Reasons;
use Goldnead\Suppression\SuppressionService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

/**
 * The reason the suppression layer is a package rather than a folder inside
 * `statamic-marketing`.
 *
 * A hard bounce is a property of the mailbox. If the list had stayed inside the
 * marketing addon, this addon would have gone on writing immediate mail and
 * weekly digests to an address marketing had already given up on — same
 * application, same sending reputation, same dead mailbox — and the argument
 * that justified making hard bounces global would have been abandoned in the
 * same breath it was made.
 *
 * So these cases are not "notifications also has a gate". They are the case the
 * separation was made for, and if they were absent the separation bought
 * nothing.
 */
beforeEach(function (): void {
    Mail::fake();

    $this->suppressions = app(SuppressionService::class);

    Notifications::registerType('lead.assigned', function ($type): void {
        $type->label('Lead zugewiesen')->defaultChannels(['mail']);
    });

    Notifications::registerType('system.outage', function ($type): void {
        // Types can declare themselves required, and the preference resolver
        // then returns true before it reads anything stored. That is the reason
        // the gate cannot live in the preference layer.
        $type->label('Störung')->required()->defaultChannels(['mail']);
    });
});

it('does not send an immediate notification mail to a suppressed address', function (): void {
    $this->suppressions->suppress('blocked@example.test', Reasons::HARD_BOUNCE);

    Notifications::notify(Identity::user(1, 'blocked@example.test'), 'lead.assigned', ['message' => 'x']);

    Mail::assertNothingSent();

    // The persisted row is still written. This decides how somebody is reached,
    // never whether the thing happened — switching off a channel silences a
    // nudge, it does not erase history.
    expect(NotificationItem::query()->count())->toBe(1);
});

it('still sends to an address nobody blocked', function (): void {
    Notifications::notify(Identity::user(2, 'fine@example.test'), 'lead.assigned', ['message' => 'x']);

    Mail::assertSent(NotificationMail::class, 1);
});

it('blocks a required type too, because a suppression is not a preference', function (): void {
    // `PreferenceResolver::allows()` returns true unconditionally for a required
    // type. A legal block that a notification type can declare itself exempt
    // from is not a block, which is why this check sits in the channel rather
    // than in the preference layer.
    $this->suppressions->suppress('blocked@example.test', Reasons::COMPLAINT);

    Notifications::notify(Identity::user(1, 'blocked@example.test'), 'system.outage', ['message' => 'x']);

    Mail::assertNothingSent();
});

it('withholds the mail when the gate cannot answer', function (): void {
    Schema::drop('suppressions');

    Notifications::notify(Identity::user(1, 'anyone@example.test'), 'lead.assigned', ['message' => 'x']);

    // Not knowing is not permission. NotificationManager swallows channel
    // exceptions through report(), so the channel returns rather than throws —
    // but it returns without sending.
    Mail::assertNothingSent();
});

it('keeps a suppressed address out of the weekly digest', function (): void {
    Notifications::registerType('community.reply', function ($type): void {
        $type->label('Antwort')->defaultChannels(['digest']);
    });

    $blocked = Identity::user(10, 'blocked@example.test');
    $fine = Identity::user(11, 'fine@example.test');

    Notifications::notify($blocked, 'community.reply', ['message' => 'a']);
    Notifications::notify($fine, 'community.reply', ['message' => 'b']);

    $this->suppressions->suppress('blocked@example.test', Reasons::COMPLAINT);

    $this->artisan('notifications:send-digests', ['--frequency' => 'weekly'])->assertExitCode(0);

    Mail::assertSent(DigestMail::class, 1);
    Mail::assertNotSent(DigestMail::class, fn (DigestMail $mail) => $mail->hasTo('blocked@example.test'));
});

/**
 * The placement, not just the presence.
 *
 * `markSent()` stamps `digested_at` on every collected item and writes the run
 * row for the window. Gating after it would burn the suppressed recipient's
 * items — marked as digested, never delivered, and never resurfaced if the
 * suppression is later released. This is what makes "the check is before
 * markSent" a testable claim rather than a comment.
 */
it('leaves the suppressed recipient\'s items pending, so a release resurfaces them', function (): void {
    Notifications::registerType('community.reply', function ($type): void {
        $type->label('Antwort')->defaultChannels(['digest']);
    });

    $blocked = Identity::user(10, 'blocked@example.test');

    Notifications::notify($blocked, 'community.reply', ['message' => 'a']);

    $this->suppressions->suppress('blocked@example.test', Reasons::COMPLAINT);

    $this->artisan('notifications:send-digests', ['--frequency' => 'weekly'])->assertExitCode(0);

    expect(NotificationItem::query()->whereNotNull('digested_at')->count())
        ->toBe(0, 'the suppressed recipient\'s items were burned rather than left for the next run');

    // Released with the full audit trail, and the next run delivers.
    $this->suppressions->releaseComplaint(
        'blocked@example.test',
        'user:1',
        'The recipient confirmed in writing that the complaint was filed against another sender.',
    );

    $this->artisan('notifications:send-digests', ['--frequency' => 'weekly'])->assertExitCode(0);

    Mail::assertSent(DigestMail::class, 1);
    expect(NotificationItem::query()->whereNotNull('digested_at')->count())->toBe(1);
});

it('withholds the digest when the gate cannot answer', function (): void {
    Notifications::registerType('community.reply', function ($type): void {
        $type->label('Antwort')->defaultChannels(['digest']);
    });

    Notifications::notify(Identity::user(10, 'anyone@example.test'), 'community.reply', ['message' => 'a']);

    Schema::drop('suppressions');

    $this->artisan('notifications:send-digests', ['--frequency' => 'weekly'])->assertExitCode(0);

    Mail::assertNothingSent();

    // And nothing was burned on the way, so the next run after the database
    // recovers still has something to send.
    expect(NotificationItem::query()->whereNotNull('digested_at')->count())->toBe(0);
});
