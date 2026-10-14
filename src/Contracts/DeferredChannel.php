<?php

namespace Goldnead\Notifications\Contracts;

use Goldnead\IdentityContracts\Identity;
use Goldnead\Notifications\Models\NotificationItem;
use Goldnead\Notifications\Sending\DeferredChannelOutcome;

/**
 * A channel that can report what a deferred send attempt produced.
 *
 * This is optional on purpose: {@see Channel} keeps its void `send()` and
 * every existing channel keeps working. But a channel that only has `send()`
 * cannot tell the dispatcher whether a mail left, was withheld or failed —
 * and a delivery row whose channel cannot answer must never be recorded as
 * accepted. `NotificationManager::notifyDeferred()` therefore gives rows only
 * to channels implementing this interface, and the dispatcher re-checks it
 * before sending.
 *
 * The contract mirrors `Channel`: the preference resolver has already decided
 * *whether* to deliver before the channel is called. The suppression gate
 * remains the channel's own exception, as on the immediate path.
 */
interface DeferredChannel
{
    /**
     * One deferred send attempt. Called under the delivery row's lock, inside
     * the transaction that will record the outcome.
     *
     * Expected failures come back as a {@see DeferredChannelOutcome} rather
     * than an exception; an exception is treated as a transport error and
     * retried by the dispatcher, which reports it.
     */
    public function sendDeferred(NotificationItem $item, Identity $recipient): DeferredChannelOutcome;
}
