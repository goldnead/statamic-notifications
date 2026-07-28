<?php

use Goldnead\Notifications\Tests\Fixtures\NotificationsDataFixture;
use Goldnead\Notifications\Tests\MigrationPathTestCase;

/**
 * The migrations, run against a database that already holds data.
 *
 * Every migration check this addon had ran against tables it had created itself
 * moments earlier and then filled by hand — a fresh install with a bit of data
 * in it. That is not a thin spot in the coverage. It is coverage pointing away
 * from the only case a migration can be wrong about: a table with rows in it,
 * written under an older schema, by an install nobody involved has ever seen.
 *
 * What it let through was not a crash. Up to 1.0.6 the rebuild migration
 * resolved the duplicates the pre-1.0.4 index had permitted by keeping the
 * highest id of each group and deleting the rest, and said so in one `info()`
 * line. On an empty database that code path never executes, so every run of
 * every suite was green and nothing ever exercised the only thing it does.
 *
 * The cases below install a published release, put data in, and migrate
 * forward. The half of them that matters is not the one that checks the
 * migration stops — it is the one that counts the rows afterwards.
 *
 * Every assertion about a guarantee is behavioural. "The migration ran" and
 * "the constraint is there" are not the same statement, and mistaking one for
 * the other is how statamic-marketing shipped three releases with its consent
 * unique missing. Nothing here checks an exit code or an index name. It writes
 * the row the constraint is supposed to refuse and requires the database to
 * refuse it.
 */
/**
 * The pre-1.0.4 install, in the form the engine under test could actually have
 * held.
 *
 * On SQLite and Postgres that is 1.0.3 exactly as published. On MySQL it is the
 * narrowed variant, because the published 1.0.3 preferences table carries a
 * five-column unique over four varchar(255) columns — 3212 bytes against
 * InnoDB's 3072 — and could not be created at all. That is not a gap in these
 * tests, it is the incident 1.0.4 exists to fix, and it carries a conclusion
 * worth stating: no MySQL host ever held pre-1.0.4 preference rows, so no MySQL
 * host can have been affected by the follow-up migration deleting them. The
 * guard that replaced the deletion is engine-agnostic and MySQL is what hosts
 * run, so it is exercised there against the same data through the variant.
 */
function legacyInstall(): string
{
    return env('DB_DRIVER', 'sqlite') === 'mysql' ? 'v1.0.3-narrowed' : 'v1.0.3';
}

it('runs every migration against tables that already hold rows', function (): void {
    $fixture = new NotificationsDataFixture($this->isolated());
    $batch = 0;

    // Seed before each migration, not just at the start: a migration that only
    // ever meets rows written by *earlier* migrations' schema is still only
    // being tested against a fresh install. Walking the directory rather than
    // naming files means every migration is covered, including ones added long
    // after this was written — that is the whole point of the shape.
    $this->migrateStepwise($this->currentMigrations(), function () use ($fixture, &$batch): void {
        $fixture->seed($batch++);
    });

    $probe = NotificationsDataFixture::preferenceProbe($batch - 1);

    expect($this->duplicateIsAccepted('notification_preferences', $probe))
        ->toBeFalse('a contact can still hold two preferences for one type and channel after a stepwise migration over populated tables');

    expect($this->isolatedSchema()->hasColumn('notification_preferences', 'uniqueness_key'))->toBeTrue();
    expect($this->isolatedSchema()->hasColumn('notification_digest_runs', 'uniqueness_key'))->toBeTrue();
});

it('upgrades a populated install from every released schema', function (string $version): void {
    // See legacyInstall(): on MySQL the pre-1.0.4 release is reached through
    // the narrowed variant, because the published one could not be installed
    // there at all.
    $version = $version === 'v1.0.3' ? legacyInstall() : $version;

    $this->migratePath($this->releasedMigrations($version));

    $fixture = new NotificationsDataFixture($this->isolated());
    $fixture->seed(0);

    $before = $fixture->counts();

    expect($before['notification_preferences'])->toBe(7);

    // Then the upgrade, with the tables filling up further as it goes.
    $batch = 1;
    $this->migrateStepwise($this->currentMigrations(), function () use ($fixture, &$batch): void {
        $fixture->seed($batch++);
    });

    $probe = NotificationsDataFixture::preferenceProbe(0);

    expect($this->duplicateIsAccepted('notification_preferences', $probe))
        ->toBeFalse("a contact preference is not constrained after upgrading a populated {$version} install");

    // Nothing that was there before may have gone missing.
    foreach ($before as $table => $count) {
        expect($this->isolated()->table($table)->count())
            ->toBeGreaterThanOrEqual($count, "rows disappeared from {$table}");
    }

    expect($this->isolated()->table('notification_preferences')->where('uniqueness_key', '')->count())->toBe(0);
})->with(['v1.0.3', 'v1.0.6']);

/**
 * The one this release exists for.
 *
 * The setup is not invented: a contact holding two preferences for the same
 * type and channel is what the pre-1.0.4 schema permitted, because its unique
 * led with `user_id` and a contact's `user_id` is NULL. Both rows are somebody
 * saying something about how they want to be contacted, seven months apart.
 */
it('does not delete a row it was never told it could delete', function (): void {
    $this->migratePath($this->releasedMigrations(legacyInstall()));

    $fixture = new NotificationsDataFixture($this->isolated());
    $fixture->seed(0);

    $probe = NotificationsDataFixture::preferenceProbe(0);

    // The second opinion, which the old schema accepted without comment.
    $second = $fixture->duplicate('notification_preferences', $probe);
    $this->isolated()->table('notification_preferences')->where('id', $second)->update(['enabled' => 0]);

    $before = $this->isolated()->table('notification_preferences')->orderBy('id')->get();

    expect($before)->toHaveCount(8);

    // The migration meets it and stops, naming what it found.
    expect(fn () => $this->migratePath($this->currentMigrations()))
        ->toThrow(RuntimeException::class, $probe['contact_uuid']);

    $after = $this->isolated()->table('notification_preferences')->orderBy('id')->get();

    // Not "roughly the same number of rows". The same rows, with every value
    // they had before still in place. Up to 1.0.6 the row with the lower id was
    // gone by this point and the only trace was a line in the log.
    //
    // Compared column by column against the columns that existed beforehand:
    // the migration does add `uniqueness_key` and fill it before it stops, and
    // it is entitled to — that step is additive, it destroys nothing, and it is
    // what lets the retry recognise how far the first attempt got.
    $columns = array_keys((array) $before->first());

    expect($after->count())->toBe($before->count())
        ->and($after->pluck('id')->all())->toBe($before->pluck('id')->all())
        ->and($after->map(fn ($row) => collect((array) $row)->only($columns)->all())->all())
        ->toEqual($before->map(fn ($row) => (array) $row)->all());
});

/**
 * The same setup, run through the migrations as 1.0.6 actually published them,
 * so the claim above is measured rather than asserted. This is what an operator
 * on 1.0.4, 1.0.5 or 1.0.6 got, and it is not recoverable afterwards: the row
 * is gone and nothing recorded what was in it.
 */
it('shows what the 1.0.6 migration did with the same rows', function (): void {
    $this->migratePath($this->releasedMigrations(legacyInstall()));

    $fixture = new NotificationsDataFixture($this->isolated());
    $fixture->seed(0);

    $probe = NotificationsDataFixture::preferenceProbe(0);
    $fixture->duplicate('notification_preferences', $probe);

    expect($this->isolated()->table('notification_preferences')->count())->toBe(8);

    $this->migratePath($this->releasedMigrations('v1.0.6'));

    // Seven. One row was removed by a schema migration, without being asked.
    expect($this->isolated()->table('notification_preferences')->count())->toBe(7);
});

it('picks up an install the migration stopped halfway through', function (): void {
    $this->migratePath($this->releasedMigrations(legacyInstall()));

    $fixture = new NotificationsDataFixture($this->isolated());
    $fixture->seed(0);

    $probe = NotificationsDataFixture::preferenceProbe(0);
    $loser = $fixture->duplicate('notification_preferences', $probe);

    expect(fn () => $this->migratePath($this->currentMigrations()))->toThrow(RuntimeException::class);

    // The abort left the column behind — that step is additive and ran. What it
    // must not have left behind is a table with no constraint on it: the check
    // happens before anything is dropped.
    expect($this->isolatedSchema()->hasColumn('notification_preferences', 'uniqueness_key'))->toBeTrue();
    expect($this->ranMigrations())->not->toContain('2026_07_28_000001_rebuild_notification_uniqueness_keys');

    // The operator decides, which is the only party that can.
    $this->isolated()->table('notification_preferences')->where('id', $loser)->delete();

    $this->migratePath($this->currentMigrations());

    expect($this->ranMigrations())->toContain('2026_07_28_000001_rebuild_notification_uniqueness_keys');

    expect($this->duplicateIsAccepted('notification_preferences', $probe))
        ->toBeFalse('the constraint was not built when the interrupted migration was picked back up');
});

it('reports the same state through the integrity command', function (): void {
    $this->migratePath($this->releasedMigrations(legacyInstall()));

    $fixture = new NotificationsDataFixture($this->isolated());
    $fixture->seed(0);

    $probe = NotificationsDataFixture::preferenceProbe(0);
    $fixture->duplicate('notification_preferences', $probe);

    expect(fn () => $this->migratePath($this->currentMigrations()))->toThrow(RuntimeException::class);

    $this->artisan('notifications:uniqueness-integrity', ['--database' => MigrationPathTestCase::CONNECTION])
        ->expectsOutputToContain($probe['contact_uuid'])
        ->assertExitCode(1);

    // Reporting is all it does.
    expect($this->isolated()->table('notification_preferences')->count())->toBe(8);
});

it('confirms the guarantee on a healthy install', function (): void {
    $this->migratePath($this->currentMigrations());

    (new NotificationsDataFixture($this->isolated()))->seed(0);

    $this->artisan('notifications:uniqueness-integrity', ['--database' => MigrationPathTestCase::CONNECTION])
        ->expectsOutputToContain('enforced by the database')
        ->assertExitCode(0);
});
