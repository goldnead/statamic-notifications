<?php

namespace Goldnead\Notifications\Contracts;

use Goldnead\IdentityContracts\Identity;
use Goldnead\Notifications\Models\NotificationItem;

/**
 * Who a deferred delivery goes to — asked at dispatch time, not at creation.
 *
 * The item row stores who the recipient was when the fact happened. For a
 * send that happens later that snapshot is not good enough: an address read
 * from it can be stale, and mailing a stale address is exactly the failure a
 * durable queue must not bake in. The resolver turns the row's join keys back
 * into a *current* identity.
 *
 * Returning null is a decision, not an error state: the dispatcher cancels
 * the delivery (`recipient_unresolvable`) rather than guessing. An
 * implementation must never fall back to the address stored on the item —
 * that fallback is what the contract exists to prevent.
 *
 * A host whose directory can answer for more than Statamic users (a CRM, a
 * members table) rebinds this interface.
 */
interface DeferredRecipientResolver
{
    public function resolve(NotificationItem $item): ?Identity;
}
