<?php

namespace Goldnead\Notifications\Sending;

use Goldnead\IdentityContracts\Facades\IdentityContext;
use Goldnead\IdentityContracts\Identity;
use Goldnead\Notifications\Contracts\DeferredRecipientResolver;
use Goldnead\Notifications\Models\NotificationItem;
use Statamic\Facades\User;

/**
 * The default answer to "who does a deferred delivery go to, today?".
 *
 * The item row stores who the recipient was when the fact happened. For a
 * send that happens later, that snapshot is not good enough: people change
 * their address, and mailing the old one is exactly the failure a durable
 * queue must not bake in. So the row's join key is used to find the
 * *current* record, and the identity — above all the e-mail — is read from
 * that record, never from the item.
 *
 * Only Statamic users are resolvable here: a `user_id` on the row, and a
 * user that still exists. Anything else — a contact recipient, a deleted
 * account — returns null, and the dispatcher cancels the delivery rather
 * than guessing. A host whose directory can answer for contacts too binds
 * its own resolver.
 */
class StatamicDeferredRecipientResolver implements DeferredRecipientResolver
{
    public function resolve(NotificationItem $item): ?Identity
    {
        if ($item->user_id === null || $item->user_id === '') {
            return null;
        }

        $user = User::find($item->user_id);

        if ($user === null) {
            return null;
        }

        // Through the shared identity machinery rather than by hand, so a
        // host's resolvers and CRM join keys apply here too. The identity is
        // built from the user as they are now; the address stored on the
        // item never enters the picture.
        $identity = IdentityContext::resolve($user);

        return $identity->isIdentified() ? $identity : null;
    }
}
