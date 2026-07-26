<?php

namespace Goldnead\Notifications\Contracts;

use Goldnead\IdentityContracts\Identity;

/**
 * Answers "who should the digest command walk?".
 *
 * The addon cannot know where a host keeps its people — a users table, a CRM,
 * a config list of operator addresses. The default implementation derives the
 * answer from the notifications themselves, which is always correct if never
 * complete.
 */
interface RecipientDirectory
{
    /**
     * @return iterable<Identity>
     */
    public function digestRecipients(string $frequency): iterable;
}
