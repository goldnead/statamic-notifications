<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Brings installs that already ran the two create-migrations onto the
 * `uniqueness_key` shape those migrations now produce.
 *
 * Editing a published migration only reaches installs that never ran it. The
 * MySQL hosts are in exactly that position — their run died on the oversized
 * unique and was never recorded, so the corrected version runs there on its
 * own. SQLite and Postgres hosts are not: neither enforces InnoDB's key limit,
 * so the original migration succeeded, was recorded, and will never be looked
 * at again. Without this file those installs would keep a table with no
 * `uniqueness_key` column while the models write to one.
 *
 * ---------------------------------------------------------------------------
 * Two things about this file changed in 1.0.7, and both are about what a
 * migration is allowed to do.
 *
 * **It used to delete rows.** The index it builds is stricter than the one it
 * replaces. The old unique led with `user_id`, which is NULL for every contact
 * recipient, and no engine constrains a NULL — so duplicate preferences and
 * duplicate digest runs for contacts were not corruption, they were ordinary
 * permitted data. Up to 1.0.6 this migration resolved that by keeping the
 * highest id of each group, deleting the rest, and reporting it with a single
 * `info()` line.
 *
 * That is not a migration's decision to make. Which of two preference rows is
 * the opinion a person currently holds, and which digest run is the one the
 * install will stand behind when somebody asks whether an e-mail went out, are
 * questions about that install's history. A log line is not consent, and the
 * rows were gone before anybody read it. It now stops instead, names every
 * group that is in the way by its natural values, and leaves the table exactly
 * as it found it — including the old unique, because the check happens before
 * anything is dropped. The operator lists the rows with
 * `php artisan notifications:uniqueness-integrity`, decides, and migrates
 * again.
 *
 * **It used to call two Eloquent models.** It imported NotificationPreference
 * and NotificationDigestRun for their static `uniquenessKeyFor()`, so the value
 * a migration published today writes into a column depended on a class that is
 * free to change tomorrow — and in the digest case on *instantiating* that
 * class, which boots its global scopes and save hooks inside a schema step. A
 * migration has to do the same thing in two years that it does today; a model
 * has to be free to change. The encoding is therefore frozen into this file at
 * the shape it was published with. `tests/Unit/FrozenUniquenessKeyTest.php`
 * requires it to still agree with the models, so a future change to the
 * encoding fails there rather than silently writing a third format into
 * installs that have not run this yet — and gets the recompute migration that
 * Support\UniquenessKey's class doc has always asked for.
 * ---------------------------------------------------------------------------
 *
 * Re-runnable, which the 1.0.6 version was not: it returned early whenever the
 * `uniqueness_key` column existed, so a run that added the column and then died
 * would, on the retry, walk straight past the index it had not yet built. Every
 * step below asks the schema what is actually there.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rebuild(
            table: 'notification_preferences',
            index: 'notif_pref_unique',
            natural: ['brand_id', 'user_id', 'contact_uuid', 'type', 'channel'],
            key: fn (object $row) => $this->uniquenessKey([
                $row->user_id,
                $row->contact_uuid,
                (string) $row->type,
                (string) $row->channel,
            ]),
        );

        $this->rebuild(
            table: 'notification_digest_runs',
            index: 'notif_digest_run_unique',
            natural: ['brand_id', 'user_id', 'contact_uuid', 'frequency', 'window_start'],
            key: fn (object $row) => $this->uniquenessKey([
                $row->user_id,
                $row->contact_uuid,
                (string) $row->frequency,
                $this->asDateTimeString($row->window_start),
            ]),
        );
    }

    public function down(): void
    {
        foreach ([
            'notification_preferences' => ['notif_pref_unique', ['brand_id', 'user_id', 'contact_uuid', 'type', 'channel']],
            'notification_digest_runs' => ['notif_digest_run_unique', ['brand_id', 'user_id', 'contact_uuid', 'frequency', 'window_start']],
        ] as $table => [$index, $legacy]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'uniqueness_key')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($index) {
                $blueprint->dropUnique($index);
                $blueprint->dropColumn('uniqueness_key');
            });

            // Restored only where the engine tolerates it. On MySQL this is the
            // very index that could not be created, so re-adding it would make
            // the rollback fail instead of the migration.
            if (DB::connection()->getDriverName() !== 'mysql') {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->unique($legacy, $index));
            }
        }
    }

    /**
     * @param  list<string>  $natural  the columns the new index means, in the terms a person reads
     * @param  callable(object): string  $key
     */
    protected function rebuild(string $table, string $index, array $natural, callable $key): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        // 1. The column. Additive, and guarded per table: after an interrupted
        //    run one of the two tables can have it while the other does not.
        if (! Schema::hasColumn($table, 'uniqueness_key')) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->char('uniqueness_key', 64)->default('');
            });
        }

        // 2. Backfill only the rows that carry no key. Rows the model has
        //    already written are left alone, so a second run is a no-op rather
        //    than a rewrite of the whole table.
        DB::table($table)->where('uniqueness_key', '')->orderBy('id')->chunkById(500, function ($rows) use ($table, $key) {
            foreach ($rows as $row) {
                DB::table($table)->where('id', $row->id)->update(['uniqueness_key' => $key($row)]);
            }
        });

        // 3. Nothing left to do if the index is already there over the right
        //    columns: the fresh-install case, and the second-run case.
        $existing = collect(Schema::getIndexes($table))->firstWhere('name', $index);

        if ($existing && ($existing['unique'] ?? false) && $existing['columns'] === ['brand_id', 'uniqueness_key']) {
            return;
        }

        // 4. Stop here if the rows cannot carry it. Deliberately *before* the
        //    old unique comes off: an abort must never leave the table with
        //    less protection than it had when the run started. That is the
        //    whole lesson of the statamic-marketing 1.6.1 incident, where the
        //    statement that failed came after the statement that dropped.
        $this->guardAgainstDuplicates($table, $index, $natural);

        // The legacy unique may be absent: it was never created on MySQL, and a
        // host may have dropped it by hand after the failed deploy. Asked, not
        // assumed — a Blueprint closure records commands rather than running
        // them, so a try/catch around dropUnique() would catch nothing.
        $hasLegacyIndex = $existing !== null;

        Schema::table($table, function (Blueprint $blueprint) use ($index, $hasLegacyIndex) {
            if ($hasLegacyIndex) {
                $blueprint->dropUnique($index);
            }

            $blueprint->unique(['brand_id', 'uniqueness_key'], $index);
        });
    }

    /**
     * Stop, and say what is in the way, if the rows cannot carry the index.
     *
     * The groups are reported by their natural columns and their ids, never by
     * the hash they collide on: a SHA-256 tells the operator nothing about
     * which rows to go and look at.
     *
     * @param  list<string>  $natural
     */
    protected function guardAgainstDuplicates(string $table, string $index, array $natural): void
    {
        $duplicates = DB::table($table)
            ->select('brand_id', 'uniqueness_key')
            ->selectRaw('count(*) as occurrences')
            ->groupBy('brand_id', 'uniqueness_key')
            ->havingRaw('count(*) > 1')
            ->orderBy('brand_id')
            ->limit(25)
            ->get();

        if ($duplicates->isEmpty()) {
            return;
        }

        $listed = $duplicates
            ->map(function (object $group) use ($table, $natural) {
                $rows = DB::table($table)
                    ->where('brand_id', $group->brand_id)
                    ->where('uniqueness_key', $group->uniqueness_key)
                    ->orderBy('id')
                    ->get();

                $described = collect($natural)
                    ->map(fn (string $column) => $column.'='.($rows->first()->{$column} ?? 'NULL'))
                    ->implode(' ');

                return '  '.$described.' (x'.$group->occurrences.': ids '.$rows->pluck('id')->implode(', ').')';
            })
            ->implode(PHP_EOL);

        throw new RuntimeException(
            "Cannot build the unique index [{$index}] on [{$table}]: rows already exist that it would reject."
            .PHP_EOL.$listed.PHP_EOL
            .'Nothing was changed and nothing was removed. These rows are legal under the index being '
            .'replaced, which led with a column that is NULL for every contact recipient and therefore '
            .'constrained nothing for them. Which of them is the row to keep is a question about this '
            .'install, not about the schema. Run `php artisan notifications:uniqueness-integrity` for the '
            .'full list with ids, values and timestamps, delete the rows that are not the ones to keep, '
            .'then migrate again.'
        );
    }

    /**
     * The value `Support\UniquenessKey::of()` produced when this migration was
     * published, restated here so that it keeps producing it.
     *
     * Not duplication for its own sake. The encoding is part of the stored
     * data, and UniquenessKey's class doc already says that changing it
     * invalidates every key written so far and needs a migration that
     * recomputes them. This file is one of those. If it called the current
     * class instead, a later change to the encoding would silently change what
     * this migration writes on every install that has not yet run it, and those
     * installs would end up holding keys in a format no recompute migration
     * ever accounted for.
     *
     * @param  array<int, string|int|null>  $parts
     */
    protected function uniquenessKey(array $parts): string
    {
        $canonical = '';

        foreach ($parts as $part) {
            if ($part === null) {
                $canonical .= '-;';

                continue;
            }

            $value = (string) $part;
            $canonical .= strlen($value).':'.$value.';';
        }

        return hash('sha256', $canonical);
    }

    /**
     * The window in the string form the column stores it in, so a value read
     * back out of the database hashes to the key the model writes.
     *
     * Frozen at `Y-m-d H:i:s` — what `getDateFormat()` returns on every grammar
     * this addon supports — rather than asked of the connection, for the same
     * reason as the encoding above.
     */
    protected function asDateTimeString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return Carbon::parse($value)->format('Y-m-d H:i:s');
    }
};
