<?php

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\IdentityContracts\Identity;
use Goldnead\Notifications\Contracts\DigestSource;
use Goldnead\Notifications\Digest\DigestBuilder;
use Goldnead\Notifications\Facades\Notifications;
use Goldnead\Notifications\Sources\LeadHubSource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
        ->and($collected['extras']['leadhub']['overdue_followups'])->toBe(2);
});

it('ignores completed follow-ups', function (): void {
    seedFollowup('5', ['completed_at' => now()]);

    expect($this->builder->collect(Identity::user(5), 'weekly')['extras'])->toBe([]);
});

it('does not attribute another user\'s follow-ups', function (): void {
    seedFollowup('99');

    expect($this->builder->collect(Identity::user(5), 'weekly')['extras'])->toBe([]);
});

it('makes a digest worth sending even without any notification', function (): void {
    // The reason a source exists at all: nobody was ever *notified* about a
    // task that is still open, but the weekly mail should mention it.
    seedFollowup();

    $collected = $this->builder->collect(Identity::user(5), 'weekly');

    expect($collected['items'])->toHaveCount(0)
        ->and($this->builder->isEmpty($collected))->toBeFalse();
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

    expect($this->builder->collect(Identity::user(5), 'weekly')['extras']['leadhub']['overdue_followups'])->toBe(1);
});

it('survives a source that throws', function (): void {
    Notifications::registerSource('broken', fn () => new class implements DigestSource
    {
        public function collect(Identity $recipient, Carbon $s, Carbon $e): array
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
