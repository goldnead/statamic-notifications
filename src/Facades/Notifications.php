<?php

namespace Goldnead\Notifications\Facades;

use Closure;
use Goldnead\Notifications\Channels\ChannelRegistry;
use Goldnead\Notifications\Digest\SourceRegistry;
use Goldnead\Notifications\Models\NotificationItem;
use Goldnead\Notifications\NotificationManager;
use Goldnead\Notifications\Preferences\PreferenceResolver;
use Goldnead\Notifications\Types\NotificationType;
use Goldnead\Notifications\Types\TypeRegistry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;

/**
 * @method static NotificationItem|null notify(mixed $recipient, string $type, array $attributes = [])
 * @method static Collection notifyMany(iterable $recipients, string $type, array $attributes = [])
 * @method static void markRead(NotificationItem|int $item)
 * @method static int markAllRead(mixed $recipient)
 * @method static Collection forRecipient(mixed $recipient, ?int $limit = null)
 * @method static int unreadCount(mixed $recipient)
 * @method static array render(NotificationItem $item)
 * @method static NotificationType registerType(string|NotificationType $type, ?Closure $configure = null)
 * @method static void registerSource(string $handle, string|Closure $source)
 * @method static void registerChannel(string $handle, string|Closure $channel)
 * @method static TypeRegistry types()
 * @method static ChannelRegistry channels()
 * @method static SourceRegistry sources()
 * @method static PreferenceResolver preferences()
 * @method static bool enabled()
 *
 * @see NotificationManager
 */
class Notifications extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'notifications';
    }
}
