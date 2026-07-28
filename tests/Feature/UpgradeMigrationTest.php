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
    // save on that row would create a second row instead of updating it.
    expect($rows->firstWhere('user_id', '1')->uniqueness_key)
        ->toBe(NotificationPreference::uniquenessKeyFor('1', null, 'community.mention', 'mail'));
});

it('clears out the duplicates the old index could not prevent, keeping the newest', function (): void {
    // Two rows the five-column unique happily accepted, because user_id is NULL
    // for a contact and NULL never equals NULL.
    $older = insertLegacyPreference(['contact_uuid' => 'c-1', 'enabled' => 1]);
    $newer = insertLegacyPreference(['contact_uuid' => 'c-1', 'enabled' => 0]);

    expect(DB::table('notification_preferences')->count())->toBe(2);

    runUpgradeMigration();

    $survivors = DB::table('notification_preferences')->get();

    expect($survivors)->toHaveCount(1)
        ->and($survivors->first()->id)->toBe($newer)
        ->and((bool) $survivors->first()->enabled)->toBeFalse();

    unset($older);
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
