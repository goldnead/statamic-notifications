<?php

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\IdentityContracts\Identity;
use Goldnead\Notifications\Contracts\DigestSource;
use Goldnead\Notifications\Digest\DigestBuilder;
use Goldnead\Notifications\Facades\Notifications;
use Goldnead\Notifications\Mail\DigestMail;
use Goldnead\Notifications\Sources\LeadHubSource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Notifications::registerSource('leadhub', LeadHubSource::class);
    $this->builder = app(DigestBuilder::class);
});

/** A follow-up belongs to a contact; the contact carries the owner. */
function seedFollowup(string $owner = '5', array $followup = [], ?int $brandId = null): int
{
    $contactId = DB::table('leadhub_contacts')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'email' => Str::uuid().'@example.com',
        'email_normalized' => Str::uuid().'@example.com',
        'status' => 'lead',
        'assigned_to' => $owner,
        'brand_id' => $brandId ?? app('brand-context')->defaultId(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('leadhub_followups')->insert(array_merge([
        'uuid' => (string) Str::uuid(),
        'contact_id' => $contactId,
        'due_at' => now()->subDay(),
        'completed_at' => null,
        'brand_id' => $brandId ?? app('brand-context')->defaultId(),
        'created_at' => now(),
        'updated_at' => now(),
    ], $followup));

    return $contactId;
}

it('contributes overdue follow-ups to the digest', function (): void {
    seedFollowup();
    seedFollowup();

    $collected = $this->builder->collect(Identity::user(5), 'weekly');

    expect($collected['extras'])->toHaveKey('leadhub')
        ->and($collected['extras']['leadhub']['line'])->toBeString()
        ->and($collected['extras']['leadhub']['line'])->toContain('2')
        // What arrives in the mail is what the source wrote, not a dump of it.
        ->and($collected['extras']['leadhub']['line'])->not->toContain('{');
});

it('ignores completed follow-ups', function (): void {
    seedFollowup('5', ['completed_at' => now()]);

    expect($this->builder->collect(Identity::user(5), 'weekly')['extras'])->toBe([]);
});

it('does not attribute another user\'s follow-ups', function (): void {
    seedFollowup('99');

    expect($this->builder->collect(Identity::user(5), 'weekly')['extras'])->toBe([]);
});

it('makes a digest worth sending once, and not again', function (): void {
    // Turned around on 22.09.2026. Until then this test held that a source
    // "makes a digest worth sending even without any notification" — full stop,
    // for as long as the follow-up stayed open. That expectation *was* the bug:
    // an open follow-up is still overdue next week, so `extras` was never empty,
    // `isEmpty()` never said "empty", and the weekly mail went out forever with
    // nothing in it. The first half of the sentence still holds — a source alone
    // does carry a digest — the second half is new: only while it is news.
    seedFollowup();

    $collected = $this->builder->collect(Identity::user(5), 'weekly');

    expect($collected['items'])->toHaveCount(0)
        ->and($this->builder->isEmpty($collected))->toBeFalse();

    $this->builder->markSent(Identity::user(5), 'weekly', $collected);

    expect($this->builder->isEmpty($this->builder->collect(Identity::user(5), 'weekly')))->toBeTrue();
});

it('does not mail the same overdue follow-up on the next run', function (): void {
    Mail::fake();
    everyRunReaches(Identity::user(5, 'lead@example.com'));

    // Nothing but the follow-up: no notification item anywhere. This is the
    // shape Adrian reported — a weekly mail that arrives with no content.
    seedFollowup();

    $this->artisan('notifications:send-digests', [
        '--frequency' => 'weekly',
        '--now' => now()->toDateTimeString(),
    ])->assertSuccessful();

    Mail::assertSentCount(1);

    // And what it says is a sentence, not a payload. A brace in the rendered
    // body means the old `<pre>{{ json_encode($payload) }}` block is back.
    Mail::assertSent(DigestMail::class, fn (DigestMail $mail) => ! str_contains($mail->render(), '{'));

    // The next run, with nothing changed in between. The follow-up is still
    // open and still overdue, and must not be reported a second time.
    //
    // Both offsets below are load-bearing, and each one hides the bug if it is
    // wrong:
    //
    //   - Not the same second, or the two runs would share a window start and
    //     the idempotency guard would refuse the second send on its own.
    //   - A day, not a week: the second window has to still *contain* the
    //     follow-up (due yesterday). Move it a week out and the follow-up falls
    //     out of the window by date alone, so the run stays silent whether or
    //     not `lastReported()` is doing its work — the test would then prove
    //     nothing about the mechanism it exists for.
    $this->artisan('notifications:send-digests', [
        '--frequency' => 'weekly',
        '--now' => now()->addDay()->toDateTimeString(),
    ])->assertSuccessful();

    Mail::assertSentCount(1);
});

it('never counts another brand\'s follow-ups into a digest', function (): void {
    $this->enableMultiBrand();
    $brandA = $this->makeBrand('brand-a');
    $brandB = $this->makeBrand('brand-b');

    seedFollowup('5', [], $brandA->id);
    seedFollowup('5', [], $brandB->id);

    // The source reads through the query builder, which bypasses LeadHub's
    // global brand scope — the filter has to hold on its own.
    BrandContext::setCurrent($brandA);

    expect($this->builder->collect(Identity::user(5), 'weekly')['extras']['leadhub']['line'])->toContain('1');
});

it('survives a source that throws', function (): void {
    Notifications::registerSource('broken', fn () => new class implements DigestSource
    {
        public function collect(Identity $recipient, Carbon $since, Carbon $until): array
        {
            throw new RuntimeException('boom');
        }
    });

    seedFollowup();

    // One addon's broken query must not silence everybody's weekly mail.
    $collected = $this->builder->collect(Identity::user(5), 'weekly');

    expect($collected['extras'])->toHaveKey('leadhub')
        ->and($collected['extras'])->not->toHaveKey('broken');
});
