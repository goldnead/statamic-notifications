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
 *
 * There is exactly one exception, and it is not a preference: a channel that
 * reaches a mailbox must consult the suppression gate first. A preference is
 * what a recipient wants; a suppression is what the mailbox can take — a hard
 * bounce means the address is gone, a complaint means writing to it again
 * carries legal weight. Neither can be expressed in the preference layer,
 * because `PreferenceResolver::allows()` returns true unconditionally for a
 * `required` type before it reads anything stored, and a legal block that a
 * type can declare itself exempt from is not a block. See `MailChannel`.
 */
interface Channel
{
    public function send(NotificationItem $item, Identity $recipient): void;
}
