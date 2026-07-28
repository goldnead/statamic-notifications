<?php

use Goldnead\Notifications\Models\NotificationPreference;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The other half of the fix. Correcting a published migration only reaches
 * installs that never ran it — the MySQL hosts, whose run died on the oversized
 * index and was never recorded. SQLite and Postgres hosts ran it successfully
 * and will never look at it again, so they need the follow-up migration to
 * reach the same schema.
 *
 * Each test here puts the database back into the pre-1.0.4 shape and runs that
 * follow-up over it, with the kind of data the old schema permitted.
 *
 * The centre of gravity moved in 1.0.7. Up to 1.0.6 the follow-up resolved the
 * duplicates the old index had allowed by keeping the highest id and deleting
 * the rest, and this file asserted that it did. It now has to prove the
 * opposite: that `php artisan migrate` cannot remove a row.
 */
beforeEach(function (): void {
    Schema::dropIfExists('notification_preferences');

    // The 1.0.3 table. `type` and `channel` are narrowed from varchar(255) only
    // so that MySQL will accept the legacy shape at all — the byte limit itself
    // is covered by IndexKeyLengthTest, and what is under test here is the data.
    Schema::create('notification_preferences', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('brand_id')->index();
        $table->string('user_id')->nullable();
        $table->uuid('contact_uuid')->nullable();
        $table->string('type', 64);
        $table->string('channel', 64);
        $table->boolean('enabled');
        $table->string('frequency')->nullable();
        $table->timestamps();

        $table->unique(['brand_id', 'user_id', 'contact_uuid', 'type', 'channel'], 'notif_pref_unique');
        $table->index(['brand_id', 'user_id'], 'notif_pref_brand_user_idx');
    });
});

function runUpgradeMigration(): void
{
    (require __DIR__.'/../../database/migrations/2026_07_28_000001_rebuild_notification_uniqueness_keys.php')->up();
}

function insertLegacyPreference(array $overrides = []): int
{
    return DB::table('notification_preferences')->insertGetId(array_merge([
        'brand_id' => 1,
        'user_id' => null,
        'contact_uuid' => null,
        'type' => 'community.mention',
        'channel' => 'mail',
        'enabled' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

it('adds the key column and backfills every existing row', function (): void {
    insertLegacyPreference(['user_id' => '1']);
    insertLegacyPreference(['user_id' => '2']);

    runUpgradeMigration();

    expect(Schema::hasColumn('notification_preferences', 'uniqueness_key'))->toBeTrue();

    $rows = DB::table('notification_preferences')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('uniqueness_key')->filter()->unique())->toHaveCount(2);

    // The backfilled value must be the one the model would write, or the next
    // save on that row would create a second row instead of updating it. The
    // migration computes it from its own frozen copy of the encoding rather
    // than from the model — see FrozenUniquenessKeyTest for why, and for the
    // check that keeps the two in step.
    expect($rows->firstWhere('user_id', '1')->uniqueness_key)
        ->toBe(NotificationPreference::uniquenessKeyFor('1', null, 'community.mention', 'mail'));
});

/**
 * The one that matters. Up to 1.0.6 this migration deleted a row here, reported
 * it with an `info()` line, and carried on — so the first anybody knew of it
 * was a preference reverting to a value the person had replaced, or a digest
 * run missing from the record of what had been sent.
 */
it('deletes nothing when duplicates are in the way', function (): void {
    // Two rows the five-column unique happily accepted, because user_id is NULL
    // for a contact and NULL never equals NULL.
    $older = insertLegacyPreference(['contact_uuid' => 'c-1', 'enabled' => 1]);
    $newer = insertLegacyPreference(['contact_uuid' => 'c-1', 'enabled' => 0]);

    expect(DB::table('notification_preferences')->count())->toBe(2);

    expect(fn () => runUpgradeMigration())->toThrow(RuntimeException::class);

    $survivors = DB::table('notification_preferences')->orderBy('id')->get();

    expect($survivors)->toHaveCount(2)
        ->and($survivors->pluck('id')->all())->toBe([$older, $newer])
        // Not merely present: unchanged. The older row still holds the value it
        // held, rather than having been "resolved" into the newer one.
        ->and((bool) $survivors->firstWhere('id', $older)->enabled)->toBeTrue()
        ->and((bool) $survivors->firstWhere('id', $newer)->enabled)->toBeFalse();
});

it('names the rows that are in the way instead of choosing between them', function (): void {
    $first = insertLegacyPreference(['contact_uuid' => 'c-1']);
    $second = insertLegacyPreference(['contact_uuid' => 'c-1']);

    expect(fn () => runUpgradeMigration())
        ->toThrow(RuntimeException::class, 'contact_uuid=c-1');

    try {
        runUpgradeMigration();
    } catch (RuntimeException $e) {
        // The ids, so the operator can go and look at exactly these rows, and
        // the command that lists them in full.
        expect($e->getMessage())
            ->toContain("ids {$first}, {$second}")
            ->toContain('notifications:uniqueness-integrity')
            ->toContain('Nothing was changed and nothing was removed');
    }
});

/**
 * The abort must not cost the table the protection it already had. This is the
 * shape that took statamic-marketing down: the statement that failed came after
 * the statement that dropped, no engine rolls DDL back, and the install was
 * left with no constraint at all and no record that anything had happened.
 */
it('leaves the old unique standing when it refuses', function (): void {
    insertLegacyPreference(['contact_uuid' => 'c-1']);
    insertLegacyPreference(['contact_uuid' => 'c-1']);

    // A pair the legacy index does constrain: both indexed columns non-NULL.
    insertLegacyPreference(['user_id' => '7', 'contact_uuid' => 'c-7']);

    expect(fn () => runUpgradeMigration())->toThrow(RuntimeException::class);

    expect(fn () => insertLegacyPreference(['user_id' => '7', 'contact_uuid' => 'c-7']))
        ->toThrow(QueryException::class);
});

/**
 * And it has to be able to pick the job back up. The 1.0.6 version could not:
 * it returned as soon as it saw a `uniqueness_key` column, which the aborted
 * run has already added — so on the retry it walked straight past the index it
 * had never built and reported success.
 */
it('finishes the job once the operator has resolved the duplicates', function (): void {
    insertLegacyPreference(['contact_uuid' => 'c-1', 'enabled' => 1]);
    $loser = insertLegacyPreference(['contact_uuid' => 'c-1', 'enabled' => 0]);

    expect(fn () => runUpgradeMigration())->toThrow(RuntimeException::class);

    // The column is there now; only the index is missing. This is the state a
    // retry has to recognise.
    expect(Schema::hasColumn('notification_preferences', 'uniqueness_key'))->toBeTrue();

    DB::table('notification_preferences')->where('id', $loser)->delete();

    runUpgradeMigration();

    // Behavioural: the constraint the migration exists to install now refuses
    // the row it is supposed to refuse.
    expect(fn () => NotificationPreference::create([
        'contact_uuid' => 'c-1',
        'type' => 'community.mention',
        'channel' => 'mail',
        'enabled' => false,
    ]))->toThrow(QueryException::class);
});

it('leaves the upgraded table enforcing uniqueness for contacts', function (): void {
    insertLegacyPreference(['contact_uuid' => 'c-1']);

    runUpgradeMigration();

    expect(fn () => NotificationPreference::create([
        'contact_uuid' => 'c-1',
        'type' => 'community.mention',
        'channel' => 'mail',
        'enabled' => false,
    ]))->toThrow(QueryException::class);
});

it('does nothing the second time it runs', function (): void {
    insertLegacyPreference(['user_id' => '1']);

    runUpgradeMigration();
    $before = DB::table('notification_preferences')->get()->toArray();

    runUpgradeMigration();

    expect(DB::table('notification_preferences')->get()->toArray())->toEqual($before);
});

it('does nothing on a fresh install, where the create-migration already did it', function (): void {
    Schema::dropIfExists('notification_preferences');
    (require __DIR__.'/../../database/migrations/2026_01_01_000002_create_notification_preferences_table.php')->up();

    runUpgradeMigration();

    expect(Schema::hasColumn('notification_preferences', 'uniqueness_key'))->toBeTrue();

    // Still exactly one unique on the table, still the hashed one.
    $uniques = collect(Schema::getIndexes('notification_preferences'))
        ->filter(fn (array $index) => $index['unique'] && ! $index['primary']);

    expect($uniques)->toHaveCount(1)
        ->and($uniques->first()['columns'])->toBe(['brand_id', 'uniqueness_key']);
});
