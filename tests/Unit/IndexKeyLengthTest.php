<?php

use Illuminate\Support\Facades\DB;

/**
 * The regression test for the defect that took v1.0.3 down on production.
 *
 * InnoDB refuses any index wider than 3072 bytes, and under utf8mb4 a
 * `varchar(255)` costs 1020 of them. `notif_pref_unique` spanned four such
 * columns plus a `char(36)` — 3212 bytes — and MySQL rejected the table with
 * SQLSTATE 1071. The suite was green throughout, because SQLite has no key
 * limit, no per-character byte cost and no fixed column widths: the very
 * arithmetic that fails on MySQL does not exist there to be tested.
 *
 * So this test does not ask the database. It compiles the addon's own
 * migrations through Laravel's MySQL grammar in pretend mode — no server, no
 * connection, nothing to install in CI — and measures the DDL that MySQL would
 * have received. It reads the real migration files, so it cannot drift from
 * them, and it fails on the next oversized index rather than on the next
 * deploy.
 */
const INNODB_MAX_KEY_BYTES = 3072;

it('keeps every index the migrations create inside the InnoDB key limit', function () {
    $schema = compileMigrationsForMysql();

    expect($schema['indexes'])->not->toBeEmpty();

    foreach ($schema['indexes'] as $index) {
        $bytes = 0;

        foreach ($index['columns'] as $column) {
            $width = $schema['columns'][$index['table']][$column] ?? null;

            expect($width)->not->toBeNull(
                "Index {$index['name']} covers unknown column {$column}."
            );

            $bytes += $width;
        }

        expect($bytes)->toBeLessThanOrEqual(
            INNODB_MAX_KEY_BYTES,
            "Index {$index['name']} on {$index['table']} needs {$bytes} bytes under utf8mb4; ".
            'InnoDB allows '.INNODB_MAX_KEY_BYTES.'. MySQL would refuse this migration with SQLSTATE 1071.'
        );
    }
});

it('still spends less than half the key limit, leaving room for another column', function () {
    // The digest-runs unique sat at 2196 of 3072 bytes and was reported as
    // failing only because the run died one migration earlier. Being under the
    // limit by accident is what made the original design fragile, so the
    // headroom is asserted rather than hoped for.
    $schema = compileMigrationsForMysql();

    foreach ($schema['indexes'] as $index) {
        $bytes = collect($index['columns'])->sum(fn ($column) => $schema['columns'][$index['table']][$column] ?? 0);

        expect($bytes)->toBeLessThan(
            INNODB_MAX_KEY_BYTES / 2,
            "Index {$index['name']} on {$index['table']} uses {$bytes} bytes — over half the limit, ".
            'so the next column added to it is likely to break the migration.'
        );
    }
});

it('enforces preference and digest-run uniqueness through a fixed-width key', function () {
    // Uniqueness must survive the shrink: the wide natural tuple is replaced by
    // a hash of it, not by a prefix of it. A prefix would fit just as well and
    // would quietly treat two different types as one.
    $schema = compileMigrationsForMysql();

    $uniques = collect($schema['indexes'])->where('unique', true)->keyBy('name');

    expect($uniques['notif_pref_unique']['columns'])->toBe(['brand_id', 'uniqueness_key'])
        ->and($uniques['notif_digest_run_unique']['columns'])->toBe(['brand_id', 'uniqueness_key']);

    // brand_id stays a column of the index rather than an ingredient of the
    // hash, so the tenant boundary is still expressed in the schema.
    expect($uniques['notif_pref_unique']['columns'][0])->toBe('brand_id')
        ->and($uniques['notif_digest_run_unique']['columns'][0])->toBe('brand_id');
});

/**
 * Runs every migration in the addon against a MySQL connection that is never
 * opened, and returns the column widths and index definitions MySQL would see.
 *
 * @return array{columns: array<string, array<string, int>>, indexes: list<array{table: string, name: string, unique: bool, columns: list<string>}>}
 */
function compileMigrationsForMysql(): array
{
    config()->set('database.connections.key_length_probe', [
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'port' => '3306',
        'database' => 'key_length_probe',
        'username' => 'probe',
        'password' => '',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
    ]);

    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection('key_length_probe');

    try {
        // pretend() short-circuits every statement before a PDO instance is
        // needed, so this compiles the DDL without a server anywhere in sight.
        $queries = DB::connection('key_length_probe')->pretend(function () {
            foreach (glob(__DIR__.'/../../database/migrations/*.php') as $file) {
                (require $file)->up();
            }
        });
    } finally {
        DB::setDefaultConnection($previous);
        DB::purge('key_length_probe');
    }

    $columns = [];
    $indexes = [];

    foreach (array_column($queries, 'query') as $sql) {
        if (preg_match('/^create table `(\w+)` \((.*)\)(?: default character set| collate|$)/s', $sql, $match)) {
            foreach (splitTopLevel($match[2]) as $definition) {
                if (preg_match('/^`(\w+)` (.+)$/', trim($definition), $column)) {
                    $columns[$match[1]][$column[1]] = mysqlIndexBytes($column[2]);
                }
            }

            continue;
        }

        if (preg_match('/^alter table `(\w+)` add (unique|index) `(\w+)`\((.+)\)$/', $sql, $match)) {
            $indexes[] = [
                'table' => $match[1],
                'name' => $match[3],
                'unique' => $match[2] === 'unique',
                'columns' => array_map(
                    fn ($column) => trim($column, ' `'),
                    explode(',', $match[4])
                ),
            ];
        }
    }

    return ['columns' => $columns, 'indexes' => $indexes];
}

/** Splits a column list on commas that are not inside parentheses. */
function splitTopLevel(string $list): array
{
    $parts = [];
    $depth = 0;
    $buffer = '';

    foreach (str_split($list) as $character) {
        if ($character === '(') {
            $depth++;
        } elseif ($character === ')') {
            $depth--;
        }

        if ($character === ',' && $depth === 0) {
            $parts[] = $buffer;
            $buffer = '';

            continue;
        }

        $buffer .= $character;
    }

    return array_merge($parts, [$buffer]);
}

/** Worst-case bytes this column type occupies in an index under utf8mb4. */
function mysqlIndexBytes(string $type): int
{
    if (preg_match('/^(?:var)?char\((\d+)\)/', $type, $match)) {
        return (int) $match[1] * 4;
    }

    return match (true) {
        str_starts_with($type, 'tinyint') => 1,
        str_starts_with($type, 'smallint') => 2,
        str_starts_with($type, 'mediumint') => 3,
        str_starts_with($type, 'int') => 4,
        str_starts_with($type, 'bigint') => 8,
        str_starts_with($type, 'timestamp'), str_starts_with($type, 'datetime') => 8,
        str_starts_with($type, 'date') => 3,
        // Blobs and JSON cannot be indexed whole at all. Reported as oversized
        // so an index that reaches for one fails here rather than on MySQL.
        default => INNODB_MAX_KEY_BYTES + 1,
    };
}
