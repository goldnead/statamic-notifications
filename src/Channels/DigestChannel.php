<?php

namespace Goldnead\Notifications\Channels;

use Goldnead\IdentityContracts\Identity;
use Goldnead\Notifications\Contracts\Channel;
use Goldnead\Notifications\Models\NotificationItem;

/**
 * Deliberately does nothing at notify time.
 *
 * The item is already persisted with `digested_at` null, which is precisely
 * "waiting for the next digest". The digest command claims it later. Doing the
 * work here instead would mean either sending immediately (not a digest) or
 * keeping a second queue of pending items (a second source of truth).
 */
class DigestChannel implements Channel
{
    public function send(NotificationItem $item, Identity $recipient): void
    {
        // No-op by design — see the class docblock.
    }
}
