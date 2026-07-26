<?php

namespace Goldnead\Notifications\Contracts;

use Goldnead\IdentityContracts\Identity;
use Illuminate\Support\Carbon;

/**
 * Lets another addon contribute to a digest without owning notifications.
 *
 * The archetype is a follow-up list: nobody was "notified" about a task that is
 * still open, but the weekly mail should mention it. A source answers "what
 * should this person also see for this window?".
 */
interface DigestSource
{
    /**
     * @return array<string, mixed> Empty array when there is nothing to add.
     */
    public function collect(Identity $recipient, Carbon $windowStart, Carbon $windowEnd): array;
}
