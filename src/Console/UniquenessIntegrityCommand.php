<?php

namespace Goldnead\Notifications\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Answers the two questions the 1.0.4 migration left open, and reports the
 * rows it is not willing to decide about.
 *
 * `php artisan migrate` reporting success means the migrations ran. It does not
 * mean the constraints they were supposed to leave behind are in place, and it
 * says nothing at all about the rows. This command asks the database instead:
 * which indexes are actually on the two tables right now, and which rows would
 * the intended index refuse.
 *
 * It never deletes anything. Since 1.0.7 neither does the migration. Where
 * duplicates exist they are preferences somebody expressed or digest runs an
 * install recorded, both permitted by the index that is being replaced —
 * whose leading `user_id` is NULL for every contact recipient, and no engine
 * constrains a NULL. Which of them is the row to keep is a question about that
 * install's history. `--repair` rebuilds the index and nothing else, and
 * refuses to run while there is anything for it to reject.
 */
class UniquenessIntegrityCommand extends Command
{
    protected $signature = 'notifications:uniqueness-integrity
        {--database= : The connection to inspect (default: the configured one)}
        {--repair : Rebuild the uniqueness indexes, if and only if nothing would have to be deleted for them}';

    protected $description = 'Check that the preference and digest-run uniqueness indexes are in place and that the rows can carry them';

    /**
     * table => [index name, the natural columns the index means]
     *
     * @var array<string, array{0: string, 1: list<string>}>
     */
    private const TABLES = [
        'notification_preferences' => ['notif_pref_unique', ['brand_id', 'user_id', 'contact_uuid', 'type', 'channel']],
        'notification_digest_runs' => ['notif_digest_run_unique', ['brand_id', 'user_id', 'contact_uuid', 'frequency', 'window_start']],
    ];

    public function handle(): int
    {
        $connection = DB::connection($this->option('database') ?: null);
        $schema = Schema::connection($this->option('database') ?: null);

        $healthy = true;

        foreach (self::TABLES as $table => [$index, $natural]) {
            if (! $schema->hasTable($table)) {
                $this->components->info("There is no {$table} table on this connection; nothing to check.");

                continue;
            }

            $healthy = $this->inspect($connection, $schema, $table, $index, $natural) && $healthy;
        }

        return $healthy ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  list<string>  $natural
     */
    private function inspect(Connection $connection, Builder $schema, string $table, string $index, array $natural): bool
    {
        $hasKey = $schema->hasColumn($table, 'uniqueness_key');

        $present = collect($schema->getIndexes($table))->firstWhere('name', $index);

        $this->line('');
        $this->components->twoColumnDetail('<fg=gray>table</>', $table);
        $this->components->twoColumnDetail('<fg=gray>rows</>', (string) $connection->table($table)->count());
        $this->components->twoColumnDetail('<fg=gray>uniqueness_key column</>', $hasKey ? 'yes' : 'no');
        $this->components->twoColumnDetail(
            '<fg=gray>uniqueness index</>',
            $present ? $present['name'].' ('.implode(', ', $present['columns']).')' : '<fg=red>none</>'
        );

        // Measured on the natural columns, never on `uniqueness_key`. The hash
        // is a representation of that tuple, and asking the question of the
        // hash would only find the duplicates the backfill has already agreed
        // about. What matters is which rows the *intended* index would refuse,
        // whether or not anything has computed a key for them yet.
        $duplicates = $this->duplicates($connection, $table, $natural);

        $wanted = ['brand_id', 'uniqueness_key'];

        if (! $hasKey) {
            $this->components->warn(
                "{$table} has no uniqueness_key column. This install has not run the 1.0.4 migration yet. "
                .'Run `php artisan migrate`.'
            );
        } elseif (! $present) {
            $this->components->error(
                "There is no unique index on {$table}. Nothing stops the same recipient from holding two "
                .'rows for the same key, and nothing has since the index was dropped.'
            );
        } elseif ($present['columns'] !== $wanted || ! ($present['unique'] ?? false)) {
            $this->components->warn(sprintf(
                'The index on %s is [%s] over (%s); this schema expects a unique over (%s). Run `php artisan migrate`.',
                $table,
                $present['name'],
                implode(', ', $present['columns']),
                implode(', ', $wanted),
            ));
        }

        $this->reportDuplicates($table, $natural, $duplicates);

        if ($this->option('repair')) {
            return $this->repair($schema, $table, $index, $present, $duplicates->isNotEmpty()) === self::SUCCESS;
        }

        $healthy = $hasKey
            && $present
            && ($present['unique'] ?? false)
            && $present['columns'] === $wanted
            && $duplicates->isEmpty();

        if ($healthy) {
            $this->components->info("{$table}: one row per recipient and key — enforced by the database.");

            return true;
        }

        $this->components->bulletList(array_filter([
            $duplicates->isNotEmpty()
                ? 'Decide which of the rows above is the row to keep and delete the others. Nothing else can.'
                : null,
            'Then run `php artisan migrate`, which rebuilds the index and finishes the interrupted update.',
            'Or run this command again with --repair to rebuild only the index.',
        ]));

        return false;
    }

    /**
     * Groups of rows that share a recipient and a key inside a brand.
     *
     * @param  list<string>  $natural
     */
    private function duplicates(Connection $connection, string $table, array $natural): Collection
    {
        // A NULL never equals a NULL in `group by`… except that it does: SQL
        // groups NULLs together even though it refuses to constrain them, which
        // is precisely the mismatch that let these rows in. So this query finds
        // exactly what the hashed index would refuse.
        return $connection->table($table)
            ->select($natural)
            ->selectRaw('count(*) as occurrences')
            ->groupBy($natural)
            ->havingRaw('count(*) > 1')
            ->orderBy('brand_id')
            ->get()
            ->map(function ($group) use ($connection, $table, $natural) {
                $query = $connection->table($table);

                foreach ($natural as $column) {
                    $group->{$column} === null
                        ? $query->whereNull($column)
                        : $query->where($column, $group->{$column});
                }

                return (object) [
                    'group' => $group,
                    'rows' => $query->orderBy('id')->get(),
                ];
            });
    }

    /**
     * @param  list<string>  $natural
     */
    private function reportDuplicates(string $table, array $natural, Collection $duplicates): void
    {
        if ($duplicates->isEmpty()) {
            $this->components->twoColumnDetail('<fg=gray>duplicate rows</>', 'none');

            return;
        }

        $this->line('');
        $this->components->error(sprintf(
            '%d group(s) in %s hold more than one row for the same recipient and key. The index being '
            .'replaced allowed them: it led with user_id, which is NULL for every contact, and no engine '
            .'constrains a NULL.',
            $duplicates->count(),
            $table,
        ));

        foreach ($duplicates as $duplicate) {
            $this->line('');
            $this->line('  <fg=yellow>'.collect($natural)
                ->map(fn (string $column) => $column.'='.($duplicate->group->{$column} ?? 'NULL'))
                ->implode(' ').'</>');

            $this->table(
                ['id', 'created_at', 'updated_at'],
                $duplicate->rows->map(fn ($row) => [
                    $row->id,
                    $row->created_at ?? '—',
                    $row->updated_at ?? '—',
                ])->all(),
            );
        }
    }

    /**
     * @param  array<string, mixed>|null  $present
     */
    private function repair(Builder $schema, string $table, string $index, ?array $present, bool $hasDuplicates): int
    {
        $this->line('');

        if (! $schema->hasColumn($table, 'uniqueness_key')) {
            $this->components->error(
                "Refusing to touch {$table}: it has no uniqueness_key column, so there is no index for this "
                .'command to build. Run `php artisan migrate` — that is the step that adds it.'
            );

            return self::FAILURE;
        }

        if ($hasDuplicates) {
            $this->components->error(
                "Refusing to rebuild the index on {$table}: the rows above would be rejected by it. Which of "
                .'them is the row to keep is a question about people and about what this install has already '
                .'sent, not about rows, so this command will not answer it. Remove the ones that are not, '
                .'then run --repair again.'
            );

            return self::FAILURE;
        }

        $wanted = ['brand_id', 'uniqueness_key'];

        if ($present && ($present['unique'] ?? false) && $present['columns'] === $wanted) {
            $this->components->info("The unique on {$table} is already in place over (".implode(', ', $wanted).'); nothing to repair.');

            return self::SUCCESS;
        }

        if ($present) {
            $schema->table($table, fn (Blueprint $blueprint) => $blueprint->dropUnique($index));
        }

        $schema->table($table, fn (Blueprint $blueprint) => $blueprint->unique($wanted, $index));

        $this->components->info("Rebuilt [{$index}] on {$table} over (".implode(', ', $wanted).').');

        $this->components->warn(
            'This restored the constraint only. Run `php artisan migrate` to finish the update itself — an '
            .'interrupted migration is still recorded as not run.'
        );

        return self::SUCCESS;
    }
}
