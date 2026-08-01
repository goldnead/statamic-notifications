<?php

use Goldnead\Notifications\Models\NotificationDigestRun;
use Goldnead\Notifications\Models\NotificationPreference;
use Goldnead\Notifications\Support\UniquenessKey;
use Illuminate\Support\Carbon;

/**
 * The migration that backfills `uniqueness_key` carries its own copy of the
 * encoding. This file is the reason that is safe, and the alarm if it stops
 * being.
 *
 * Up to 1.0.6 the migration imported NotificationPreference and
 * NotificationDigestRun and called their static `uniquenessKeyFor()`. That
 * looks like reuse and is really a dependency in the wrong direction: a
 * migration is a statement about one moment in a database's history and has to
 * keep making it, unchanged, for as long as any install might still be behind.
 * A model is the opposite — it is the current shape of the code and is meant to
 * move. Three concrete ways the old arrangement breaks, none of them
 * hypothetical for a package that ships to hosts it cannot see:
 *
 * 1. Rename, move or delete either class and `php artisan migrate` stops at
 *    "Class not found" on every install that has not run this file yet, with
 *    nothing about the message pointing at the migration directory.
 * 2. Change the encoding — which UniquenessKey's own class doc contemplates,
 *    and which needs a fresh recompute migration when it happens — and this
 *    file silently starts writing the new format on installs that are still
 *    behind, which the fresh recompute migration will then not know to fix,
 *    because as far as the migrations table is concerned those installs are up
 *    to date.
 * 3. `NotificationDigestRun::uniquenessKeyFor()` instantiates the model to
 *    normalise the window, which boots the HasBrand global scope and the saving
 *    hook inside a schema step, on a connection that may not be the default
 *    one.
 *
 * So the migration froze the encoding at the shape it published. The cost of
 * freezing is drift, and this is what catches it: if the model's answer and the
 * migration's answer ever diverge, that is the moment somebody has to write the
 * recompute migration, not the moment two formats quietly start coexisting.
 */
function migrationUniquenessKey(array $parts): string
{
    $migration = require __DIR__.'/../../database/migrations/2026_07_28_000001_rebuild_notification_uniqueness_keys.php';

    $frozen = (new ReflectionClass($migration))->getMethod('uniquenessKey');
    $frozen->setAccessible(true);

    return $frozen->invoke($migration, $parts);
}

it('does not reach for a model class', function (): void {
    $source = file_get_contents(
        __DIR__.'/../../database/migrations/2026_07_28_000001_rebuild_notification_uniqueness_keys.php'
    );

    // Comments talk about the models on purpose; code must not.
    $code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $source);

    expect($code)
        ->not->toContain('Goldnead\Notifications\Models')
        ->not->toContain('NotificationPreference')
        ->not->toContain('NotificationDigestRun')
        ->not->toContain('UniquenessKey::');
});

it('produces exactly what the preference model produces', function (?string $userId, ?string $contactUuid, string $type, string $channel): void {
    expect(migrationUniquenessKey([$userId, $contactUuid, $type, $channel]))
        ->toBe(NotificationPreference::uniquenessKeyFor($userId, $contactUuid, $type, $channel));
})->with([
    'a user' => ['42', null, 'community.mention', 'mail'],
    'a contact' => [null, 'c-1', 'community.mention', 'mail'],
    'both' => ['42', 'c-1', 'leadhub.assigned', 'database'],
    'empty strings, which must not collide with null' => ['', '', '', ''],
    'a value carrying the encoding separators' => ['4;2', '1:c;', 'a;b', 'c:d'],
]);

it('produces exactly what the digest-run model produces', function (): void {
    $window = '2026-07-28 00:00:00';

    expect(migrationUniquenessKey([null, 'c-1', 'weekly', $window]))
        ->toBe(NotificationDigestRun::uniquenessKeyFor(null, 'c-1', 'weekly', $window));

    expect(migrationUniquenessKey(['42', null, 'daily', $window]))
        ->toBe(NotificationDigestRun::uniquenessKeyFor('42', null, 'daily', $window));
});

it('normalises a window the way the model does, whatever it is handed', function (): void {
    $migration = require __DIR__.'/../../database/migrations/2026_07_28_000001_rebuild_notification_uniqueness_keys.php';

    $normalise = (new ReflectionClass($migration))->getMethod('asDateTimeString');
    $normalise->setAccessible(true);

    $expected = '2026-07-28 09:30:00';

    // A Carbon instance, the string the database hands back, and a value with a
    // different representation of the same instant all have to reduce to one
    // key — otherwise a re-run recomputes a different hash for the same row.
    foreach ([
        Carbon::parse($expected),
        $expected,
        '2026-07-28T09:30:00',
    ] as $input) {
        expect($normalise->invoke($migration, $input))->toBe($expected);
    }

    expect($normalise->invoke($migration, null))->toBeNull();
});

it('still agrees with the encoding class it was copied from', function (): void {
    // Not a duplicate of the model checks: those go through the models, this
    // goes through the shared encoder underneath them. If somebody changes
    // UniquenessKey and updates both models to match, only this one fails —
    // and it is the one that says a recompute migration is now owed.
    expect(migrationUniquenessKey(['42', null, 'a', 'b']))
        ->toBe(UniquenessKey::of(['42', null, 'a', 'b']));
});
