<?php

namespace Goldnead\Notifications;

use Closure;
use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\IdentityContracts\Facades\IdentityContext;
use Goldnead\IdentityContracts\Identity;
use Goldnead\Notifications\Channels\ChannelRegistry;
use Goldnead\Notifications\Contracts\DeferredChannel;
use Goldnead\Notifications\Digest\SourceRegistry;
use Goldnead\Notifications\Models\NotificationDelivery;
use Goldnead\Notifications\Models\NotificationItem;
use Goldnead\Notifications\Preferences\PreferenceResolver;
use Goldnead\Notifications\Types\NotificationType;
use Goldnead\Notifications\Types\TypeRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The single entry point: notify(), read state, registration of types, channels
 * and digest sources.
 *
 * Two rules, both learned from the systems this replaces:
 *   1. Notifying is idempotent. The same fact reaching two producers yields one
 *      notification, not two.
 *   2. Notifying never breaks the thing that caused it. A mail transport error
 *      must not roll back the comment somebody just posted.
 */
class NotificationManager
{
    public function __construct(
        protected TypeRegistry $types,
        protected ChannelRegistry $channels,
        protected PreferenceResolver $preferences,
        protected SourceRegistry $sources,
    ) {}

    /**
     * Deliver one notification to one recipient across every channel that
     * recipient allows for this type.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function notify(mixed $recipient, string $type, array $attributes = []): ?NotificationItem
    {
        if (! $this->enabled()) {
            return null;
        }

        $identity = IdentityContext::resolve($recipient);

        // Someone with no durable join key cannot be notified — there would be
        // no way to ever show it to them again.
        if (! $identity->isIdentified()) {
            return null;
        }

        $item = $this->persist($identity, $type, $attributes);

        if ($item === null) {
            return null;
        }

        // A pre-existing item means this fact was already delivered. Re-running
        // the channels would notify twice.
        if (! $item->wasRecentlyCreated) {
            return $item;
        }

        $this->dispatchChannels($item, $identity, $type);

        return $item;
    }

    /**
     * The durable, opt-in variant of {@see notify()}: the notification and one
     * delivery row per deferred channel are written in a single transaction,
     * and the sends happen later, through
     * `Goldnead\Notifications\Sending\DeferredDelivery::dispatchPending()` /
     * `notifications:dispatch-pending`.
     *
     * Two differences from `notify()` are the point of the method:
     *
     * - **Strict, not soothing.** A storage failure rolls the whole
     *   transaction back — item *and* rows — and the exception reaches the
     *   caller. A caller who asked for durable delivery must learn that
     *   nothing was stored; `notify()`'s rule of never breaking the thing
     *   that caused it stays `notify()`'s rule.
     * - **Nothing is sent now.** Not even the realtime nudge. The rows are
     *   the promise of a send, and only channels that can report an outcome
     *   (`DeferredChannel`) get one — a channel whose send cannot say what
     *   happened is never recorded, and never falsely counted as accepted.
     *
     * Preferences are honoured here, at creation, and again at dispatch:
     * between a fact and its send, a recipient may have switched a channel
     * off. A repeated fact (same dedupe key) returns the existing item
     * without touching any delivery row — a row that has reached a terminal
     * state is never reset.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function notifyDeferred(mixed $recipient, string $type, array $attributes = []): ?NotificationItem
    {
        if (! $this->enabled()) {
            return null;
        }

        $identity = IdentityContext::resolve($recipient);

        if (! $identity->isIdentified()) {
            return null;
        }

        return $this->persistDeferred($identity, $type, $attributes);
    }

    /**
     * Notify many recipients of the same fact. The dedupe key is scoped per
     * recipient, so one shared fact still yields one notification each.
     *
     * @param  iterable<mixed>  $recipients
     * @param  array<string, mixed>  $attributes
     */
    public function notifyMany(iterable $recipients, string $type, array $attributes = []): Collection
    {
        $items = new Collection;
        $baseKey = $attributes['dedupe_key'] ?? null;

        foreach ($recipients as $recipient) {
            $perRecipient = $attributes;

            if ($baseKey !== null) {
                $identity = IdentityContext::resolve($recipient);
                $perRecipient['dedupe_key'] = $baseKey.':'.($identity->userId ?? $identity->contactUuid ?? 'unknown');
            }

            $item = $this->notify($recipient, $type, $perRecipient);

            if ($item !== null) {
                $items->push($item);
            }
        }

        return $items;
    }

    public function markRead(NotificationItem|int $item): void
    {
        $item = $item instanceof NotificationItem ? $item : NotificationItem::query()->find($item);

        $item?->forceFill(['read_at' => now()])->save();
    }

    /** Marks everything currently unread for this recipient. */
    public function markAllRead(mixed $recipient): int
    {
        $identity = IdentityContext::resolve($recipient);

        if (! $identity->isIdentified()) {
            return 0;
        }

        return NotificationItem::query()
            ->forRecipient($identity)
            ->unread()
            ->update(['read_at' => now()]);
    }

    public function forRecipient(mixed $recipient, ?int $limit = null): Collection
    {
        $identity = IdentityContext::resolve($recipient);

        if (! $identity->isIdentified()) {
            return new Collection;
        }

        return NotificationItem::query()
            ->forRecipient($identity)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit ?? (int) config('notifications.list_limit', 30))
            ->get();
    }

    public function unreadCount(mixed $recipient): int
    {
        $identity = IdentityContext::resolve($recipient);

        if (! $identity->isIdentified()) {
            return 0;
        }

        return NotificationItem::query()->forRecipient($identity)->unread()->count();
    }

    /** Renders an item through its registered type. */
    public function render(NotificationItem $item): array
    {
        return $this->types->get($item->type)->render($item);
    }

    // ---------------------------------------------------------------- registry

    public function registerType(string|NotificationType $type, ?Closure $configure = null): NotificationType
    {
        return $this->types->register($type, $configure);
    }

    public function registerSource(string $handle, string|Closure $source): void
    {
        $this->sources->register($handle, $source);
    }

    public function registerChannel(string $handle, string|Closure $channel): void
    {
        $this->channels->register($handle, $channel);
    }

    public function types(): TypeRegistry
    {
        return $this->types;
    }

    public function channels(): ChannelRegistry
    {
        return $this->channels;
    }

    public function sources(): SourceRegistry
    {
        return $this->sources;
    }

    public function preferences(): PreferenceResolver
    {
        return $this->preferences;
    }

    public function enabled(): bool
    {
        return (bool) config('notifications.enabled', true);
    }

    // ---------------------------------------------------------------- internal

    protected function persist(Identity $identity, string $type, array $attributes): ?NotificationItem
    {
        $row = $this->buildRow($identity, $type, $attributes);

        try {
            if ($row['dedupe_key'] === null) {
                return NotificationItem::create($row);
            }

            $existing = $this->findDuplicate($row);

            if ($existing !== null) {
                return $existing;
            }

            return NotificationItem::create($row);
        } catch (UniqueConstraintViolationException) {
            return $this->findDuplicate($row);
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                return $this->findDuplicate($row);
            }

            // Never let a notification failure escape into the caller.
            report($e);

            return null;
        }
    }

    /**
     * The column values for a notification row, shared by the immediate and
     * the deferred path so the two cannot disagree about what a fact records.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function buildRow(Identity $identity, string $type, array $attributes): array
    {
        $actor = isset($attributes['actor']) ? IdentityContext::resolve($attributes['actor']) : null;
        $subject = $attributes['subject'] ?? null;

        return [
            'brand_id' => $attributes['brand_id']
                ?? (BrandContext::hasCurrent() ? BrandContext::currentId() : BrandContext::defaultId()),
            'type' => $type,
            'recipient_type' => $identity->type,
            'recipient_id' => $identity->id,
            'user_id' => $identity->userId,
            'contact_uuid' => $identity->contactUuid,
            'email' => $identity->email,
            'actor_type' => $actor?->type,
            'actor_id' => $actor?->id,
            'actor_name' => $actor?->name,
            'subject_type' => $attributes['subject_type'] ?? ($subject instanceof Model ? $subject::class : null),
            'subject_id' => isset($attributes['subject_id'])
                ? (string) $attributes['subject_id']
                : ($subject instanceof Model ? (string) $subject->getKey() : null),
            'message' => $attributes['message'] ?? null,
            'link' => $attributes['link'] ?? null,
            'data' => $attributes['data'] ?? null,
            'dedupe_key' => $attributes['dedupe_key'] ?? null,
            'read_at' => $attributes['read_at'] ?? null,
        ];
    }

    /**
     * Item and delivery rows, or neither: one transaction, no partial state.
     * Storage failures propagate after the rollback — see `notifyDeferred()`.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function persistDeferred(Identity $identity, string $type, array $attributes): NotificationItem
    {
        $row = $this->buildRow($identity, $type, $attributes);

        return DB::transaction(function () use ($row, $identity, $type): NotificationItem {
            $item = $this->insertItemWithSavepoint($row);

            // A pre-existing item means this fact was already recorded, and
            // its deliveries are already owed or already terminal. Writing
            // rows again would duplicate the send; resetting them would
            // resurrect one that ended.
            if (! $item->wasRecentlyCreated) {
                return $item;
            }

            foreach ($this->deferredChannelsFor($identity, $type) as $channel) {
                NotificationDelivery::query()->create([
                    'notification_item_id' => $item->id,
                    'channel' => $channel,
                    'status' => NotificationDelivery::STATUS_PENDING,
                    'attempts' => 0,
                    'available_at' => now(),
                ]);
            }

            return $item;
        });
    }

    /**
     * The dedupe insert for the deferred path.
     *
     * This is not `persist()`'s insert, and the difference is a database
     * fact rather than a style choice: on PostgreSQL, catching a unique
     * violation inside a surrounding transaction leaves that transaction
     * aborted ("current transaction is aborted"), and the duplicate lookup
     * afterwards would fail with it. The nested transaction below opens a
     * savepoint, so a losing race rolls back only the insert and the
     * surrounding work stays alive. (`createOrFirst()` does the same; doing
     * it by hand keeps the duplicate lookup identical to the immediate
     * path's, global scopes included.)
     *
     * @param  array<string, mixed>  $row
     */
    protected function insertItemWithSavepoint(array $row): NotificationItem
    {
        if ($row['dedupe_key'] === null) {
            return NotificationItem::create($row);
        }

        $existing = $this->findDuplicate($row);

        if ($existing !== null) {
            return $existing;
        }

        try {
            return DB::transaction(fn (): NotificationItem => NotificationItem::create($row), 1);
        } catch (QueryException $e) {
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }

            return $this->findDuplicate($row);
        }
    }

    /**
     * The channels that owe a delivery row for this fact: configured for
     * deferral, able to report an outcome, and allowed by the recipient's
     * preferences *now*, at creation — they are checked again at dispatch.
     *
     * A channel that is not on the list, or whose registered implementation
     * only has the legacy void `send()`, gets no row at all. No row is the
     * honest outcome: nothing is sent, and nothing is recorded as if it had
     * been.
     *
     * @return list<string>
     */
    protected function deferredChannelsFor(Identity $identity, string $type): array
    {
        $channels = [];

        foreach (array_unique((array) config('notifications.deferred.channels', ['in_app', 'mail'])) as $handle) {
            $handle = (string) $handle;

            if (! $this->channelSupportsDeferred($handle)) {
                continue;
            }

            if (! $this->preferences->allows($identity, $type, $handle)) {
                continue;
            }

            $channels[] = $handle;
        }

        return $channels;
    }

    /**
     * Can this channel take part in deferred delivery at all?
     *
     * A Closure registration cannot be inspected for the interface, and
     * without `DeferredChannel` there is no outcome to persist — both mean
     * no row, for the same reason: unspeakable outcomes must not be guessed.
     */
    protected function channelSupportsDeferred(string $handle): bool
    {
        $registered = $this->channels->all()[$handle] ?? null;

        if (! is_string($registered)) {
            return false;
        }

        return in_array(DeferredChannel::class, (array) class_implements($registered), true);
    }

    protected function findDuplicate(array $row): ?NotificationItem
    {
        return NotificationItem::withoutGlobalScopes()
            ->where('brand_id', $row['brand_id'])
            ->where('dedupe_key', $row['dedupe_key'])
            ->first();
    }

    protected function isUniqueViolation(QueryException $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'unique constraint')
            || str_contains($message, 'duplicate entry')
            || str_contains($message, 'unique violation');
    }

    protected function dispatchChannels(NotificationItem $item, Identity $identity, string $type): void
    {
        foreach ($this->channels->all() as $handle => $channel) {
            // Note the asymmetry: the persisted row is written regardless of
            // preferences, because it is the record that this happened. The
            // channels decide only how someone is *reached* — switching off
            // `in_app` silences the realtime nudge, it does not erase history.
            if (! $this->preferences->allows($identity, $type, $handle)) {
                continue;
            }

            try {
                $this->channels->resolve($handle)->send($item, $identity);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }
}
