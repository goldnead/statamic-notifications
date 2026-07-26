<?php

namespace Goldnead\Notifications\Realtime;

use Goldnead\IdentityContracts\Identity;
use Goldnead\Notifications\Events\NotificationReceived;
use Goldnead\Notifications\Models\NotificationItem;

/**
 * Optional realtime nudge. Broadcasting is off unless the host configures it,
 * and a broadcast failure is never allowed to matter — the notification is
 * already persisted, the client will pick it up on its next poll.
 *
 * The payload deliberately carries no content, only a refresh signal. Pushing
 * the notification body over a socket would duplicate the read model and leak
 * whatever the recipient is not allowed to see yet.
 */
class BroadcastAdapter
{
    public function notify(Identity $recipient, NotificationItem $item): void
    {
        if (! config('notifications.realtime.enabled', false)) {
            return;
        }

        if ($recipient->userId === null) {
            return;
        }

        try {
            NotificationReceived::dispatch($recipient->userId, $item->type);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
