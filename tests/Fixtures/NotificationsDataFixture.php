<?php

namespace Goldnead\Notifications\Tests\Fixtures;

use Illuminate\Database\Connection;
use Illuminate\Support\Str;

/**
 * Real-shaped notifications data, insertable into any released schema.
 *
 * A migration test is only worth running against rows, and rows are the one
 * thing this addon's migration coverage never had. The awkward part is that the
 * schema those rows go into changes underneath them — `uniqueness_key` appears
 * in 1.0.4 — so a fixture with a fixed column list can only seed one version of
 * the database.
 *
 * This one asks the schema what it has. Every row is built at its widest, then
 * reduced to the columns that exist at the moment of the insert, and anything
 * NOT NULL the fixture has never heard of is filled generically, so a migration
 * added next year is seeded without this file being touched.
 *
 * The shape is the one the defect lives in: recipients that are users, and
 * recipients that are contacts. Contacts are the case that matters. Their
 * `user_id` is NULL, the pre-1.0.4 unique led with that column, and no engine
 * constrains a NULL — so a contact holding two preferences for the same type
 * and channel, or two digest runs for the same window, is not corrupt data. It
 * is data the schema explicitly permitted, and it is what the migration has to
 * meet without deleting any of it.
 */
class NotificationsDataFixture
{
    /**
     * @var list<array{user: ?string, contact: ?string, type: string, channel: string, enabled: int}>
     */
    public const PREFERENCES = [
        ['user' => '1', 'contact' => null, 'type' => 'community.mention', 'channel' => 'mail', 'enabled' => 1],
        ['user' => '1', 'contact' => null, 'type' => 'community.mention', 'channel' => 'database', 'enabled' => 0],
        ['user' => '1', 'contact' => null, 'type' => 'leadhub.assigned', 'channel' => 'digest', 'enabled' => 1],
        ['user' => '2', 'contact' => null, 'type' => 'community.mention', 'channel' => 'mail', 'enabled' => 0],
        ['user' => null, 'contact' => 'contact-a', 'type' => 'community.mention', 'channel' => 'mail', 'enabled' => 1],
        ['user' => null, 'contact' => 'contact-a', 'type' => 'leadhub.assigned', 'channel' => 'mail', 'enabled' => 1],
        ['user' => null, 'contact' => 'contact-b', 'type' => 'community.mention', 'channel' => 'digest', 'enabled' => 0],
    ];

    /**
     * @var list<array{user: ?string, contact: ?string, frequency: string, offset: int}>
     */
    public const DIGEST_RUNS = [
        ['user' => '1', 'contact' => null, 'frequency' => 'weekly', 'offset' => 0],
        ['user' => '1', 'contact' => null, 'frequency' => 'weekly', 'offset' => 7],
        ['user' => '2', 'contact' => null, 'frequency' => 'daily', 'offset' => 0],
        ['user' => null, 'contact' => 'contact-a', 'frequency' => 'weekly', 'offset' => 0],
        ['user' => null, 'contact' => 'contact-b', 'frequency' => 'daily', 'offset' => 1],
    ];

    public function __construct(private Connection $connection) {}

    /**
     * A recipient the constraint has to keep to one row, for assertions to name
     * by hand. A contact on purpose: that is the recipient the old index never
     * covered.
     *
     * @return array{user_id: ?string, contact_uuid: string, type: string, channel: string}
     */
    public static function preferenceProbe(int $batch = 0): array
    {
        return [
            'user_id' => null,
            'contact_uuid' => 'contact-a'.($batch === 0 ? '' : '-b'.$batch),
            'type' => 'community.mention',
            'channel' => 'mail',
        ];
    }

    /**
     * Put one full generation of data into every notifications table that
     * exists.
     *
     * Repeatable: pass a different `$batch` to add another generation without
     * colliding with the last one. Batch 0 is the fixture above verbatim, so
     * assertions can name a row by hand.
     *
     * @return int the number of rows written across all tables
     */
    public function seed(int $batch = 0): int
    {
        $suffix = $batch === 0 ? '' : '-b'.$batch;
        $written = 0;

        if ($this->has('notification_items')) {
            foreach (['community.mention', 'leadhub.assigned', 'marketing.bounced'] as $i => $type) {
                foreach ([['1', null], [null, 'contact-a'.$suffix], [null, 'contact-b'.$suffix]] as $j => [$userId, $contactUuid]) {
                    $this->insert('notification_items', [
                        'type' => $type,
                        'recipient_type' => $userId === null ? 'contact' : 'user',
                        'recipient_id' => $userId ?? $contactUuid,
                        'user_id' => $userId,
                        'contact_uuid' => $contactUuid,
                        'email' => ($userId ?? $contactUuid).'@example.com',
                        'actor_type' => 'user',
                        'actor_id' => '9',
                        'actor_name' => 'Adrian',
                        'subject_type' => 'Goldnead\\Leadhub\\Models\\Contact',
                        'subject_id' => (string) (100 + $i * 10 + $j),
                        'message' => 'Etwas ist passiert.',
                        'link' => '/cp/leadhub/contacts/'.(100 + $i),
                        'data' => json_encode(['fixture' => true]),
                        // Globally unique per brand, which is the one guarantee
                        // this table has always made and must keep making.
                        'dedupe_key' => $type.':'.$i.':'.$j.$suffix,
                        'read_at' => $j === 0 ? now() : null,
                        'digested_at' => null,
                    ]);

                    $written++;
                }
            }
        }

        if ($this->has('notification_preferences')) {
            foreach (self::PREFERENCES as $preference) {
                $this->insert('notification_preferences', [
                    'user_id' => $preference['user'] === null ? null : $preference['user'].$suffix,
                    'contact_uuid' => $preference['contact'] === null ? null : $preference['contact'].$suffix,
                    'type' => $preference['type'],
                    'channel' => $preference['channel'],
                    'enabled' => $preference['enabled'],
                    'frequency' => $preference['channel'] === 'digest' ? 'weekly' : null,
                    'uniqueness_key' => self::uniquenessKey([
                        $preference['user'] === null ? null : $preference['user'].$suffix,
                        $preference['contact'] === null ? null : $preference['contact'].$suffix,
                        $preference['type'],
                        $preference['channel'],
                    ]),
                ]);

                $written++;
            }
        }

        if ($this->has('notification_digest_runs')) {
            foreach (self::DIGEST_RUNS as $run) {
                $windowStart = now()->startOfDay()->subDays($run['offset'])->format('Y-m-d H:i:s');
                $userId = $run['user'] === null ? null : $run['user'].$suffix;
                $contactUuid = $run['contact'] === null ? null : $run['contact'].$suffix;

                $this->insert('notification_digest_runs', [
                    'user_id' => $userId,
                    'contact_uuid' => $contactUuid,
                    'email' => ($userId ?? $contactUuid).'@example.com',
                    'frequency' => $run['frequency'],
                    'window_start' => $windowStart,
                    'window_end' => now()->startOfDay()->subDays($run['offset'])->addDay()->format('Y-m-d H:i:s'),
                    'item_count' => 3,
                    'sent_at' => now(),
                    'uniqueness_key' => self::uniquenessKey([
                        $userId,
                        $contactUuid,
                        $run['frequency'],
                        $windowStart,
                    ]),
                ]);

                $written++;
            }
        }

        return $written;
    }

    /**
     * Write a second row for a recipient that already has one.
     *
     * Only possible while the constraint is missing, which is the situation
     * being modelled: an install that has been running on the pre-1.0.4 schema,
     * where a contact's preferences were never constrained at all.
     *
     * @param  array<string, mixed>  $match
     */
    public function duplicate(string $table, array $match): int
    {
        $query = $this->connection->table($table);

        foreach ($match as $column => $value) {
            $value === null ? $query->whereNull($column) : $query->where($column, $value);
        }

        $existing = (array) $query->firstOrFail();

        return (int) $this->connection->table($table)->insertGetId(
            collect($existing)->except('id')->all()
        );
    }

    /**
     * The value `Support\UniquenessKey::of()` produces. Duplicated here on
     * purpose: a fixture that called into the encoder would stop describing the
     * schema and start agreeing with the code under test.
     *
     * @param  array<int, string|int|null>  $parts
     */
    public static function uniquenessKey(array $parts): string
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
     * How many rows every notifications table currently holds.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = [];

        foreach (self::tables() as $table) {
            if ($this->has($table)) {
                $counts[$table] = $this->connection->table($table)->count();
            }
        }

        return $counts;
    }

    /**
     * @return list<string>
     */
    public static function tables(): array
    {
        return [
            'notification_items',
            'notification_preferences',
            'notification_digest_runs',
        ];
    }

    private function has(string $table): bool
    {
        return $this->connection->getSchemaBuilder()->hasTable($table);
    }

    /**
     * Reduce a row to the columns the table has today, add timestamps, fill any
     * NOT NULL column the fixture does not know about, and insert.
     *
     * @param  array<string, mixed>  $row
     */
    private function insert(string $table, array $row): int
    {
        $columns = collect($this->connection->getSchemaBuilder()->getColumns($table))->keyBy('name');

        $row = collect($row)->only($columns->keys()->all())->all();

        if ($columns->has('created_at')) {
            $row['created_at'] = now();
            $row['updated_at'] = now();
        }

        if ($columns->has('brand_id') && ! isset($row['brand_id'])) {
            $row['brand_id'] = $this->defaultBrandId();
        }

        foreach ($columns as $name => $column) {
            if (array_key_exists($name, $row)) {
                continue;
            }

            if (($column['auto_increment'] ?? false) || ($column['nullable'] ?? true) || ($column['default'] ?? null) !== null) {
                continue;
            }

            $row[$name] = $this->genericValueFor($column, $table, $name);
        }

        return (int) $this->connection->table($table)->insertGetId($row);
    }

    /**
     * A value for a NOT NULL column this fixture has never heard of.
     *
     * Unique per row, because a column added by a future migration is most
     * likely to be added together with a unique over it — which is the shape
     * this whole file exists to catch.
     *
     * @param  array<string, mixed>  $column
     */
    private function genericValueFor(array $column, string $table, string $name): string|int
    {
        $type = strtolower((string) ($column['type_name'] ?? $column['type'] ?? 'string'));

        return match (true) {
            str_contains($type, 'int') => random_int(1, PHP_INT_MAX),
            str_contains($type, 'bool') => 0,
            str_contains($type, 'date'), str_contains($type, 'time') => (string) now(),
            default => substr(hash('sha256', $table.$name.Str::uuid()), 0, 32),
        };
    }

    private function defaultBrandId(): ?int
    {
        return $this->connection->table('brands')->where('is_default', true)->value('id')
            ?? $this->connection->table('brands')->min('id');
    }
}
