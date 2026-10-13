<?php

namespace Goldnead\Notifications\Channels;

use Goldnead\IdentityContracts\Identity;
use Goldnead\Notifications\Contracts\Channel;
use Goldnead\Notifications\Contracts\DeferredChannel;
use Goldnead\Notifications\Models\NotificationItem;
use Goldnead\Notifications\Realtime\BroadcastAdapter;
use Goldnead\Notifications\Sending\DeferredChannelOutcome;

/**
 * The persisted row IS the in-app delivery, so there is nothing left to send.
 * This channel exists to nudge any connected client to refresh, and to give the
 * preference matrix something to switch off.
 *
 * That makes it the one channel whose deferred delivery is already durable at
 * creation: the item row exists, so the notification is in-app delivered the
 * moment `notifyDeferred()` commits, and the outcome is a fact rather than a
 * hope. The broadcast nudge is best-effort on top — it is attempted, and a
 * failure of the nudge never fails the delivery.
 */
class InAppChannel implements Channel, DeferredChannel
{
    public function __construct(protected BroadcastAdapter $broadcast) {}

    public function send(NotificationItem $item, Identity $recipient): void
    {
        $this->broadcast->notify($recipient, $item);
    }

    public function sendDeferred(NotificationItem $item, Identity $recipient): DeferredChannelOutcome
    {
        $this->broadcast->notify($recipient, $item);

        return DeferredChannelOutcome::accepted(DeferredChannelOutcome::CODE_IN_APP_DURABLE);
    }
}
