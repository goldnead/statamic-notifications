<?php

namespace Goldnead\Notifications\Channels;

use Goldnead\IdentityContracts\Identity;
use Goldnead\Notifications\Contracts\Channel;
use Goldnead\Notifications\Models\NotificationItem;
use Goldnead\Notifications\Realtime\BroadcastAdapter;

/**
 * The persisted row IS the in-app delivery, so there is nothing left to send.
 * This channel exists to nudge any connected client to refresh, and to give the
 * preference matrix something to switch off.
 */
class InAppChannel implements Channel
{
    public function __construct(protected BroadcastAdapter $broadcast) {}

    public function send(NotificationItem $item, Identity $recipient): void
    {
        $this->broadcast->notify($recipient, $item);
    }
}
