<?php

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\IdentityContracts\Identity;
use Goldnead\Notifications\Contracts\DigestSource;
use Goldnead\Notifications\Contracts\SenderIdentityResolver;
use Goldnead\Notifications\Digest\DigestBuilder;
use Goldnead\Notifications\Facades\Notifications;
use Goldnead\Notifications\Mail\DigestMail;
use Goldnead\Notifications\Models\NotificationDigestRun;
use Goldnead\Notifications\Models\NotificationItem;
use Goldnead\Notifications\Sending\BrandMailer;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\View;

beforeEach(function (): void {
    $this->builder = app(DigestBuilder::class);

    Notifications::registerType('community.reply', function ($type): void {
        $type->label('Antwort')->defaultChannels(['in_app', 'digest']);
    });
});

/**
 * A source that reports a state rather than an event: whatever it is told to
 * say, it says on every run, for as long as it is registered. That is not a
 * broken source — open tasks and upcoming events are genuinely like that — and
 * it is exactly what used to force an unchanging digest out every week.
 *
 * Passing null makes it fall silent, which is how "something fell away" is
 * staged below.
 */
function sourceSays(string $handle, ?string $line): void
{
    Notifications::registerSource($handle, fn () => new class($line) implements DigestSource
    {
        public function __construct(protected ?string $line) {}

        public function collect(Identity $recipient, Carbon $since, Carbon $until): array
        {
            return $this->line === null ? [] : ['line' => $this->line];
        }
    });
}

function runDigest(object $test, ?string $now = null): void
{
    $test->artisan('notifications:send-digests', [
        '--frequency' => 'weekly',
        '--now' => $now ?? now()->toDateTimeString(),
    ])->assertSuccessful();
}

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

it('loads a source written against the older shape, and lets it contribute nothing', function (): void {
    // Two failures in one test, and the first one is the reason this file
    // declares the class at all.
    //
    // 1. A source out in the field returns `array`. Narrowing the contract's
    //    return type to the sentence itself would make this very declaration a
    //    compile-time fatal — "Declaration of … must be compatible with …" —
    //    and no try/catch in SourceRegistry could catch it, because the process
    //    dies while the class is being loaded. `notifications:send-digests`
    //    would stop dead for everybody, in every brand. If this test ever fails
    //    to load rather than failing an assertion, that is what happened.
    // 2. It answers with data and no `line`. It therefore contributes nothing:
    //    not to the mail, and not to the question of whether to send one. Any
    //    non-empty answer used to count as content, which is precisely how a
    //    source reporting a permanent state kept an empty digest going out.
    Notifications::registerSource('old-style', fn () => new class implements DigestSource
    {
        public function collect(Identity $recipient, Carbon $windowStart, Carbon $windowEnd): array
        {
            return ['overdue_followups' => 2];
        }
    });

    Mail::fake();

    // Reachable for the run, with nothing inside the window.
    notifyAt(now()->subDays(30)->toDateTimeString());

    $collected = $this->builder->collect(Identity::user(1), 'weekly');

    expect($collected['extras'])->toBe([])
        ->and($this->builder->isEmpty($collected))->toBeTrue();

    $this->artisan('notifications:send-digests', ['--frequency' => 'weekly'])->assertSuccessful();

    Mail::assertNothingSent();
});

it('keeps a contribution whole, so a published view can still lay it out', function (): void {
    // `line` decides whether a source contributes at all and is the only thing
    // the shipped template prints. It is not all a contribution is allowed to
    // carry: adriangoldner.com publishes its own digest view and builds an
    // event list out of the payload. Reducing a contribution to its sentence
    // would make that section disappear from the mail without a word.
    Notifications::registerSource('community', fn () => new class implements DigestSource
    {
        public function collect(Identity $recipient, Carbon $since, Carbon $until): array
        {
            return [
                'line' => 'Der nächste Termin: Offene Singstunde.',
                'events' => [['title' => 'Offene Singstunde']],
            ];
        }
    });

    notifyAt(now()->subDay()->toDateTimeString());

    $extras = $this->builder->collect(Identity::user(1), 'weekly')['extras'];

    expect($extras['community']['line'])->toContain('Offene Singstunde')
        ->and($extras['community']['events'])->toHaveCount(1);
});

it('does not send a digest that would repeat the last one word for word', function (): void {
    // Adrian's case, in one test. A source reporting a permanent state has
    // content every week and news only sometimes; the window check cannot tell
    // those apart, because every window is new.
    Mail::fake();
    everyRunReaches(Identity::user(7, 'chef@example.com'));
    sourceSays('tasks', 'Du hast 3 offene Aufgaben.');

    runDigest($this);
    Mail::assertSentCount(1);

    // A week on, nothing has happened. The tasks are still open.
    runDigest($this, now()->addWeek()->toDateTimeString());
    Mail::assertSentCount(1);
});

it('sends again as soon as there is more to report', function (): void {
    Mail::fake();
    everyRunReaches(Identity::user(7, 'chef@example.com'));
    sourceSays('tasks', 'Du hast 3 offene Aufgaben.');

    runDigest($this);
    Mail::assertSentCount(1);

    sourceSays('tasks', 'Du hast 4 offene Aufgaben.');

    runDigest($this, now()->addWeek()->toDateTimeString());
    Mail::assertSentCount(2);
});

it('sends again when something falls away, because less is a change too', function (): void {
    Mail::fake();
    everyRunReaches(Identity::user(7, 'chef@example.com'));
    sourceSays('tasks', 'Du hast 3 offene Aufgaben.');
    sourceSays('leadhub', 'Ein Follow-up ist überfällig.');

    runDigest($this);
    Mail::assertSentCount(1);

    // The follow-up got done. That source has nothing to add any more, and the
    // digest that remains is a different digest.
    sourceSays('leadhub', null);

    runDigest($this, now()->addWeek()->toDateTimeString());
    Mail::assertSentCount(2);
});

it('fingerprints what the digest says, not how the template says it', function (): void {
    // If the fingerprint were taken over the rendered mail, changing a colour
    // would post one more empty-handed digest to every recipient there is.
    sourceSays('tasks', 'Du hast 3 offene Aufgaben.');
    notifyAt(now()->subDay()->toDateTimeString());

    $collected = $this->builder->collect(Identity::user(1), 'weekly');
    $before = $this->builder->fingerprint($collected);
    $htmlBefore = (new DigestMail(Identity::user(1), $collected, 'weekly'))->render();

    $dir = sys_get_temp_dir().'/notifications-view-'.uniqid();
    mkdir($dir.'/mail', 0777, true);
    file_put_contents($dir.'/mail/digest.blade.php', '<p>eine vollkommen andere Vorlage</p>');

    View::prependNamespace('notifications', $dir);
    View::getFinder()->flush();

    $htmlAfter = (new DigestMail(Identity::user(1), $collected, 'weekly'))->render();

    expect($htmlAfter)->not->toBe($htmlBefore)
        ->and($this->builder->fingerprint($collected))->toBe($before);

    @unlink($dir.'/mail/digest.blade.php');
    @rmdir($dir.'/mail');
    @rmdir($dir);
});

it('does not let a run that delivered nothing swallow the next real mail', function (): void {
    Mail::fake();
    everyRunReaches(Identity::user(7, 'chef@example.com'));
    sourceSays('tasks', 'Du hast 3 offene Aufgaben.');

    // A sender identity that goes unusable between the pre-flight check and the
    // send itself: the run is recorded, the mail never leaves.
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

    $this->artisan('notifications:send-digests', ['--frequency' => 'weekly'])->assertFailed();
    Mail::assertNothingSent();

    app()->forgetInstance(BrandMailer::class);

    // Nothing has changed in the meantime — and it still has to arrive, because
    // nobody has read it yet.
    runDigest($this, now()->addWeek()->toDateTimeString());
    Mail::assertSentCount(1);
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

it('leaves out types the recipient does not want digested', function (): void {
    // Regression: a type whose default channels are in_app+mail was mailed
    // immediately AND repeated in the weekly summary a few days later.
    Notifications::registerType('crm.lead_assigned', fn ($type) => $type->defaultChannels(['in_app', 'mail']));

    Notifications::notify(Identity::user(1, 'a@example.com'), 'crm.lead_assigned', ['message' => 'sofort']);
    notifyAt(now()->subDay()->toDateTimeString(), 'im digest');

    $collected = $this->builder->collect(Identity::user(1), 'weekly');

    expect($collected['items'])->toHaveCount(1)
        ->and($collected['items']->first()->message)->toBe('im digest')
        ->and($collected['skipped'])->toHaveCount(1);
});

it('stamps skipped items so a later preference change cannot resurface them', function (): void {
    Notifications::registerType('crm.lead_assigned', fn ($type) => $type->defaultChannels(['mail']));

    $mailed = Notifications::notify(Identity::user(1, 'a@example.com'), 'crm.lead_assigned');
    notifyAt(now()->subDay()->toDateTimeString());

    $collected = $this->builder->collect(Identity::user(1), 'weekly');
    $this->builder->markSent(Identity::user(1), 'weekly', $collected);

    expect($mailed->fresh()->digested_at)->not->toBeNull();
});

it('walks every brand when none is current — the scheduler case', function (): void {
    // Regression: a scheduled run has no CP session, so no brand is current.
    // Under multi-brand the global scope then fails closed and the command
    // reported "0 digest(s)" forever without anyone noticing.
    Mail::fake();
    $this->enableMultiBrand();
    $brandA = $this->makeBrand('brand-a');
    $brandB = $this->makeBrand('brand-b');

    foreach ([$brandA, $brandB] as $brand) {
        BrandContext::runFor($brand, function (): void {
            Notifications::registerType('community.reply', fn ($t) => $t->defaultChannels(['in_app', 'digest']));
            $item = Notifications::notify(Identity::user(1, 'a@example.com'), 'community.reply', ['message' => 'x']);
            $item->forceFill(['created_at' => now()->subDay()])->save();
        });
    }

    BrandContext::forget();

    $this->artisan('notifications:send-digests', ['--frequency' => 'weekly'])->assertSuccessful();

    // One per brand — the same person in two brands is two recipients.
    Mail::assertSent(DigestMail::class, 2);
});

it('can be restricted to a single brand', function (): void {
    Mail::fake();
    $this->enableMultiBrand();
    $brandA = $this->makeBrand('brand-a');
    $this->makeBrand('brand-b');

    BrandContext::runFor($brandA, function (): void {
        Notifications::registerType('community.reply', fn ($t) => $t->defaultChannels(['in_app', 'digest']));
        $item = Notifications::notify(Identity::user(1, 'a@example.com'), 'community.reply', ['message' => 'x']);
        $item->forceFill(['created_at' => now()->subDay()])->save();
    });

    BrandContext::forget();

    $this->artisan('notifications:send-digests', ['--frequency' => 'weekly', '--brand' => 'brand-b'])->assertSuccessful();
    Mail::assertNothingSent();

    $this->artisan('notifications:send-digests', ['--frequency' => 'weekly', '--brand' => 'brand-a'])->assertSuccessful();
    Mail::assertSent(DigestMail::class, 1);
});
