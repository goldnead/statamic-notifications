<?php

namespace Goldnead\Notifications\Contracts;

use Goldnead\IdentityContracts\Identity;
use Goldnead\Notifications\Models\NotificationItem;

/**
 * A delivery route for a notification that has already been persisted.
 *
 * Channels never decide *whether* to deliver — the preference resolver has done
 * that before they are called. They only decide *how*, and they may do nothing
 * (the digest channel simply leaves the item for the next run).
 */
interface Channel
{
    public function send(NotificationItem $item, Identity $recipient): void;
}
