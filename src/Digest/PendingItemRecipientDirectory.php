<?php

namespace Goldnead\Notifications\Digest;

use Goldnead\IdentityContracts\Identity;
use Goldnead\Notifications\Contracts\RecipientDirectory;
use Goldnead\Notifications\Models\NotificationItem;

/**
 * Default directory: everyone who has an undigested notification.
 *
 * Deliberately derived from the data rather than from a user table. It cannot
 * reach someone who has nothing waiting — which is exactly right, because a
 * digest with nothing in it should not be sent. Hosts that also want digests
 * built purely from sources (open follow-ups, upcoming events) bind their own.
 */
class PendingItemRecipientDirectory implements RecipientDirectory
{
    public function digestRecipients(string $frequency): iterable
    {
        return NotificationItem::query()
            ->pendingDigest()
            ->select(['user_id', 'contact_uuid', 'email'])
            ->distinct()
            ->cursor()
            ->map(fn (NotificationItem $item) => new Identity(
                type: $item->user_id !== null ? Identity::TYPE_USER : Identity::TYPE_CONTACT,
                id: $item->user_id ?? $item->contact_uuid,
                userId: $item->user_id,
                contactUuid: $item->contact_uuid,
                email: $item->email,
            ));
    }
}
