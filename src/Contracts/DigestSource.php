<?php

namespace Goldnead\Notifications\Contracts;

use Goldnead\IdentityContracts\Identity;
use Illuminate\Support\Carbon;

/**
 * Lets another addon contribute to a digest without owning notifications.
 *
 * The archetype is a follow-up list: nobody was "notified" about a task that is
 * still open, but the weekly mail should mention it. A source answers "what is
 * new for this person since they were last told anything?".
 *
 * Two things a source has to get right, both of them learned the hard way:
 *
 *   1. **`$since` is not the start of the window, it is the end of the last
 *      digest this person actually received.** A source that ignores it and
 *      reports everything still open reports the same thing every single run —
 *      which means the digest is never empty and goes out forever with nothing
 *      in it. That is the bug this parameter exists to prevent.
 *   2. **What the shipped mail prints is `line`: one finished sentence.** Until
 *      1.10.0 a source returned whatever shape it liked and the template had no
 *      choice but to `json_encode()` it, so a digest that did have something to
 *      say said it in braces. The rest of the array is not thrown away — a host
 *      that published its own view still reads it and can lay it out richly —
 *      it simply is not what this package prints.
 *
 * A source that returns no `line` contributes nothing: not to the body, and not
 * to the question of whether the digest is worth sending at all. That is the
 * safe direction. An implementation written against the older shape keeps
 * loading and keeps running, it merely stops pushing an empty mail out of the
 * door until it says something in words.
 *
 * The signature stays as it is on purpose. Narrowing the return type to the
 * sentence itself would be a compile-time fatal in every implementation still
 * in the field, and one that no `try`/`catch` in this package can reach: the
 * process dies while the class is being loaded and takes the whole digest run
 * with it.
 */
interface DigestSource
{
    /**
     * @param  Carbon  $since  End of the last digest sent to this recipient, or
     *                         the start of the window if they never got one.
     * @return array{line?: string} Empty, or without a non-empty `line`, when
     *                              there is nothing new to report.
     */
    public function collect(Identity $recipient, Carbon $since, Carbon $until): array;
}
