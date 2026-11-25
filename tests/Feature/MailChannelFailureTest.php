<?php

use Goldnead\EmailTemplates\Snapshots\Snapshots;
use Goldnead\IdentityContracts\Identity;
use Goldnead\Notifications\Contracts\DeferredRecipientResolver;
use Goldnead\Notifications\Contracts\SenderIdentityResolver;
use Goldnead\Notifications\Facades\Notifications;
use Goldnead\Notifications\Models\NotificationDelivery;
use Goldnead\Notifications\Models\NotificationItem;
use Goldnead\Notifications\Sending\BrandMailer;
use Goldnead\Notifications\Sending\DeferredDelivery;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/../Fakes/email-templates-snapshots.php';

/**
 * N2 und N4 (Sweep 17.11.2026): Fehler rund um die Mail duerfen weder eine
 * schon rausgegangene Mail wiederholen lassen noch eine verweigerte Mail
 * unbemerkt lassen.
 */
beforeEach(function (): void {
    Snapshots::reset();

    Notifications::registerType('community.mention', fn ($type) => $type
        ->label('Erwähnung')
        ->defaultChannels(['mail'])
        ->renderUsing(fn (NotificationItem $item) => [
            'message' => 'Bea hat dich erwähnt',
            'link' => 'https://example.test/community/threads/7',
        ]));

    app()->instance(DeferredRecipientResolver::class, new class implements DeferredRecipientResolver
    {
        public function resolve(NotificationItem $item): ?Identity
        {
            return Identity::user((int) $item->user_id, 'live@example.com');
        }
    });
    app()->forgetInstance(DeferredDelivery::class);
});

afterEach(function (): void {
    NotificationItem::flushEventListeners();
});

it('wiederholt keine Mail, die schon rausging, wenn nur der Zeiger nicht gespeichert werden kann', function (): void {
    Mail::fake();
    Notifications::notifyDeferred(Identity::user(1, 'a@example.com'), 'community.mention');

    NotificationItem::saving(function (NotificationItem $item): void {
        if ($item->isDirty('email_template_snapshot_id')) {
            throw new RuntimeException('db gone');
        }
    });
    Log::spy();

    $rows = NotificationDelivery::query()->count();
    $counts = app(DeferredDelivery::class)->dispatchPending(100);

    expect($rows)->toBeGreaterThan(0)
        ->and($counts['accepted'])->toBe($rows);
    Mail::assertSentCount($rows);
    Log::shouldHaveReceived('error')->withArgs(fn ($m) => str_contains((string) $m, 'snapshot pointer'))->atLeast()->once();
});

it('loggt einen Fehler, wenn der Mailer die Sofort-Mail ablehnt', function (): void {
    app()->instance(BrandMailer::class, new class(app(SenderIdentityResolver::class)) extends BrandMailer
    {
        public function maySend(?int $brandId): bool
        {
            return true;
        }

        public function send(?int $brandId, string $to, ?string $toName, Mailable $mailable): bool
        {
            return false;
        }
    });
    Log::spy();

    Notifications::notify(Identity::user(1, 'a@example.com'), 'community.mention');

    Log::shouldHaveReceived('error')->withArgs(fn ($m) => str_contains((string) $m, 'immediate mail was not sent'))->atLeast()->once();
});
