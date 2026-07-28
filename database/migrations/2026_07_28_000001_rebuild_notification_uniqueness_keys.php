<?php

use Goldnead\Notifications\Models\NotificationDigestRun;
use Goldnead\Notifications\Models\NotificationPreference;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
 * Idempotent on both counts: a fresh install already has the column and this
 * does nothing; an install without the tables at all does nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rebuild(
            table: 'notification_preferences',
            index: 'notif_pref_unique',
            key: fn (object $row) => NotificationPreference::uniquenessKeyFor(
                $row->user_id,
                $row->contact_uuid,
                (string) $row->type,
                (string) $row->channel,
            ),
        );

        $this->rebuild(
            table: 'notification_digest_runs',
            index: 'notif_digest_run_unique',
            key: fn (object $row) => NotificationDigestRun::uniquenessKeyFor(
                $row->user_id,
                $row->contact_uuid,
                (string) $row->frequency,
                $row->window_start,
            ),
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

    protected function rebuild(string $table, string $index, callable $key): void
    {
        if (! Schema::hasTable($table) || Schema::hasColumn($table, 'uniqueness_key')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->char('uniqueness_key', 64)->default('');
        });

        DB::table($table)->orderBy('id')->chunkById(500, function ($rows) use ($table, $key) {
            foreach ($rows as $row) {
                DB::table($table)->where('id', $row->id)->update(['uniqueness_key' => $key($row)]);
            }
        });

        $this->dropDuplicates($table);

        // The legacy unique may be absent: it was never created on MySQL, and a
        // host may have dropped it by hand after the failed deploy. Asked, not
        // assumed — a Blueprint closure records commands rather than running
        // them, so a try/catch around dropUnique() would catch nothing.
        $hasLegacyIndex = collect(Schema::getIndexes($table))
            ->contains(fn (array $existing) => $existing['name'] === $index);

        Schema::table($table, function (Blueprint $blueprint) use ($index, $hasLegacyIndex) {
            if ($hasLegacyIndex) {
                $blueprint->dropUnique($index);
            }

            $blueprint->unique(['brand_id', 'uniqueness_key'], $index);
        });
    }

    /**
     * The index being replaced never constrained NULL, and `user_id` is NULL
     * for every contact recipient — so duplicates that the old schema allowed
     * may be sitting in the table, and the new index would refuse to build over
     * them. The most recent row wins: for a preference that is the last opinion
     * the person expressed, and for a digest run it is the one whose `sent_at`
     * best reflects when the recipient was last written to.
     */
    protected function dropDuplicates(string $table): void
    {
        $survivors = DB::table($table)
            ->selectRaw('max(id) as id')
            ->groupBy('brand_id', 'uniqueness_key')
            ->pluck('id');

        $doomed = DB::table($table)->whereNotIn('id', $survivors)->count();

        if ($doomed === 0) {
            return;
        }

        DB::table($table)->whereNotIn('id', $survivors)->delete();

        info("[notifications] Removed {$doomed} duplicate row(s) from {$table} that the previous unique index could not prevent.");
    }
};
