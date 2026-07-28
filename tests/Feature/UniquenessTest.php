<?php

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\IdentityContracts\Identity;
use Goldnead\Notifications\Digest\DigestBuilder;
use Goldnead\Notifications\Facades\Notifications;
use Goldnead\Notifications\Models\NotificationDigestRun;
use Goldnead\Notifications\Models\NotificationPreference;
use Illuminate\Database\QueryException;

/**
 * The uniques on these two tables were rewritten to sit on a hashed key, both
 * because the natural tuple did not fit InnoDB's 3072-byte limit and because it
 * never enforced anything for contact recipients: a SQL unique ignores NULL,
 * and `user_id` is NULL for every one of them.
 *
 * These are the guarantees the schema is supposed to provide. They run on
 * SQLite and on MySQL alike (`vendor/bin/pest -c phpunit.mysql.xml`).
 */
it('refuses a second preference row for the same user, type and channel', function (): void {
    NotificationPreference::create([
        'user_id' => '1',
        'type' => 'community.mention',
        'channel' => 'mail',
        'enabled' => true,
    ]);

    expect(fn () => NotificationPreference::create([
        'user_id' => '1',
        'type' => 'community.mention',
        'channel' => 'mail',
        'enabled' => false,
    ]))->toThrow(QueryException::class);
});

it('refuses a second preference row for a contact, whose user_id is null', function (): void {
    // The case the old index could not see. NULL is never equal to NULL, so the
    // five-column unique let a contact accumulate contradictory preferences for
    // one type and channel — and the resolver would read whichever came first.
    NotificationPreference::create([
        'contact_uuid' => 'c1b2a3d4-0000-4000-8000-000000000001',
        'type' => 'community.mention',
        'channel' => 'mail',
        'enabled' => true,
    ]);

    expect(fn () => NotificationPreference::create([
        'contact_uuid' => 'c1b2a3d4-0000-4000-8000-000000000001',
        'type' => 'community.mention',
        'channel' => 'mail',
        'enabled' => false,
    ]))->toThrow(QueryException::class);
});

it('separates a user preference from a contact preference that looks like it', function (): void {
    // The hash must distinguish "no user id" from "no contact uuid": both rows
    // carry one identifier and one absence, and they are different people.
    NotificationPreference::create([
        'user_id' => 'x',
        'type' => 'community.mention',
        'channel' => 'mail',
        'enabled' => true,
    ]);

    NotificationPreference::create([
        'contact_uuid' => 'x',
        'type' => 'community.mention',
        'channel' => 'mail',
        'enabled' => false,
    ]);

    expect(NotificationPreference::count())->toBe(2);
});

it('does not let one long type name swallow another', function (): void {
    // A prefix index would have been the cheap fix for the byte limit. These two
    // types differ only past the 64th character, and must stay two preferences.
    $shared = str_repeat('a', 80);

    NotificationPreference::create([
        'user_id' => '1',
        'type' => $shared.'.one',
        'channel' => 'mail',
        'enabled' => true,
    ]);

    NotificationPreference::create([
        'user_id' => '1',
        'type' => $shared.'.two',
        'channel' => 'mail',
        'enabled' => false,
    ]);

    expect(NotificationPreference::count())->toBe(2);
});

it('refuses to record the same digest window twice for a contact', function (): void {
    $window = ['start' => now()->subWeek()->startOfDay(), 'end' => now()->startOfDay()];

    $attributes = [
        'contact_uuid' => 'c1b2a3d4-0000-4000-8000-000000000002',
        'frequency' => 'weekly',
        'window_start' => $window['start'],
        'window_end' => $window['end'],
        'item_count' => 3,
        'sent_at' => now(),
    ];

    NotificationDigestRun::create($attributes);

    expect(fn () => NotificationDigestRun::create($attributes))->toThrow(QueryException::class);
});

it('still reports a repeated digest window as already sent rather than throwing', function (): void {
    $recipient = Identity::user(1);

    Notifications::registerType('community.mention', fn ($type) => $type->defaultChannels(['digest']));
    Notifications::notify($recipient, 'community.mention');

    $builder = app(DigestBuilder::class);
    $collected = $builder->collect($recipient, 'weekly');

    expect($builder->markSent($recipient, 'weekly', $collected))->not->toBeNull()
        ->and($builder->markSent($recipient, 'weekly', $collected))->toBeNull();
});

it('keeps the same preference in two brands as two rows', function (): void {
    $this->enableMultiBrand();

    $brandA = $this->makeBrand('brand-a');
    $brandB = $this->makeBrand('brand-b');

    foreach ([$brandA, $brandB] as $brand) {
        BrandContext::runFor($brand, fn () => NotificationPreference::create([
            'user_id' => '1',
            'type' => 'community.mention',
            'channel' => 'mail',
            'enabled' => false,
        ]));
    }

    // brand_id is a column of the unique, not an ingredient of the hash, so the
    // two rows share a uniqueness_key and are told apart by tenant alone.
    $rows = BrandContext::withoutBrandScope(fn () => NotificationPreference::all());

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('uniqueness_key')->unique())->toHaveCount(1)
        ->and($rows->pluck('brand_id')->all())->toBe([$brandA->id, $brandB->id]);
});
