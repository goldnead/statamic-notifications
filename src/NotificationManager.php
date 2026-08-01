<?php

namespace Goldnead\Notifications;

use Closure;
use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\IdentityContracts\Facades\IdentityContext;
use Goldnead\IdentityContracts\Identity;
use Goldnead\Notifications\Channels\ChannelRegistry;
use Goldnead\Notifications\Digest\SourceRegistry;
use Goldnead\Notifications\Models\NotificationItem;
use Goldnead\Notifications\Preferences\PreferenceResolver;
use Goldnead\Notifications\Types\NotificationType;
use Goldnead\Notifications\Types\TypeRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;

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
        $actor = isset($attributes['actor']) ? IdentityContext::resolve($attributes['actor']) : null;
        $subject = $attributes['subject'] ?? null;

        $row = [
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
