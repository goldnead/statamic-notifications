<?php

use Goldnead\Notifications\Models\NotificationPreference;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The command exists because the migration now refuses instead of deleting, and
 * a refusal is only useful if the operator can see what it refused over.
 *
 * It answers the question `php artisan migrate` cannot: not "did the migration
 * run", but "is the constraint on the table right now, and can the rows carry
 * it". Those are different statements, and treating them as one is how an
 * install ends up believing a guarantee it does not have.
 */
function makeLegacyPreferencesTable(): void
{
    Schema::dropIfExists('notification_preferences');

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
    });
}

function legacyPreference(array $overrides = []): int
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

function runRebuildMigration(): void
{
    (require __DIR__.'/../../database/migrations/2026_07_28_000001_rebuild_notification_uniqueness_keys.php')->up();
}

it('confirms the guarantee on a healthy install', function (): void {
    NotificationPreference::create([
        'user_id' => '1',
        'type' => 'community.mention',
        'channel' => 'mail',
        'enabled' => true,
    ]);

    $this->artisan('notifications:uniqueness-integrity')
        ->expectsOutputToContain('enforced by the database')
        ->assertExitCode(0);
});

it('lists the rows the migration refused, with their ids', function (): void {
    makeLegacyPreferencesTable();

    $first = legacyPreference(['contact_uuid' => 'c-1']);
    $second = legacyPreference(['contact_uuid' => 'c-1']);

    expect(fn () => runRebuildMigration())->toThrow(RuntimeException::class);

    $this->artisan('notifications:uniqueness-integrity')
        ->expectsOutputToContain('c-1')
        ->assertExitCode(1);

    // Reporting is all it does. Both rows are still there.
    expect(DB::table('notification_preferences')->pluck('id')->all())->toBe([$first, $second]);
});

it('says so plainly when the table has no unique on it at all', function (): void {
    // The state statamic-marketing shipped by accident and nobody could see: the
    // schema is up to date, the migration is recorded as run, and the constraint
    // it was supposed to leave behind is simply not there. Nothing announces it;
    // the install just stops refusing what it used to refuse.
    makeLegacyPreferencesTable();
    legacyPreference(['user_id' => '1']);
    runRebuildMigration();

    Schema::table('notification_preferences', fn (Blueprint $table) => $table->dropUnique('notif_pref_unique'));

    $this->artisan('notifications:uniqueness-integrity')
        ->expectsOutputToContain('no unique index')
        ->assertExitCode(1);
});

it('says which step is missing when the install never ran the migration', function (): void {
    makeLegacyPreferencesTable();
    legacyPreference(['user_id' => '1']);

    $this->artisan('notifications:uniqueness-integrity')
        ->expectsOutputToContain('has not run the 1.0.4 migration yet')
        ->assertExitCode(1);
});

it('refuses to repair while there is something in the way', function (): void {
    makeLegacyPreferencesTable();

    legacyPreference(['contact_uuid' => 'c-1']);
    $loser = legacyPreference(['contact_uuid' => 'c-1']);

    expect(fn () => runRebuildMigration())->toThrow(RuntimeException::class);

    $this->artisan('notifications:uniqueness-integrity', ['--repair' => true])
        ->assertExitCode(1);

    expect(DB::table('notification_preferences')->count())->toBe(2);

    // Behavioural: a --repair that had quietly built the index would have made
    // this throw. It did not build it, so this is still accepted.
    $extra = legacyPreference(['contact_uuid' => 'c-1']);

    expect($extra)->toBeGreaterThan($loser);
});

it('rebuilds the index once the operator has resolved it', function (): void {
    makeLegacyPreferencesTable();

    legacyPreference(['contact_uuid' => 'c-1']);
    $loser = legacyPreference(['contact_uuid' => 'c-1']);

    expect(fn () => runRebuildMigration())->toThrow(RuntimeException::class);

    DB::table('notification_preferences')->where('id', $loser)->delete();

    $this->artisan('notifications:uniqueness-integrity', ['--repair' => true])
        ->assertExitCode(0);

    expect(fn () => NotificationPreference::create([
        'contact_uuid' => 'c-1',
        'type' => 'community.mention',
        'channel' => 'mail',
        'enabled' => false,
    ]))->toThrow(QueryException::class);

    // And the outstanding migration runs afterwards without complaint.
    runRebuildMigration();
});
