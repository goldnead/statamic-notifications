<?php

namespace Goldnead\Notifications\Channels;

use Goldnead\Notifications\Facades\Notifications;
use Illuminate\Notifications\Notification;

/**
 * Makes this addon usable as a Laravel notification channel:
 *
 *     public function via($notifiable): array { return ['notifications']; }
 *     public function toNotifications($notifiable): array
 *     {
 *         return ['type' => 'lead.assigned', 'message' => '…', 'link' => '…'];
 *     }
 *
 * This is the interop path chosen over building on Laravel's own
 * `notifications` table: existing `$user->notify()` call sites can route into
 * the persisted, brand-scoped store without inheriting a schema that has no
 * brand column and no dedupe key.
 */
class LaravelChannelAdapter
{
    public function send(mixed $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toNotifications')) {
            return;
        }

        $payload = $notification->toNotifications($notifiable);

        if (! is_array($payload) || ! isset($payload['type'])) {
            return;
        }

        $type = $payload['type'];
        unset($payload['type']);

        Notifications::notify($notifiable, $type, $payload);
    }
}
