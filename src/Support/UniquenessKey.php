<?php

namespace Goldnead\Notifications\Support;

/**
 * A fixed-width stand-in for a wide, variable-length natural key.
 *
 * Two problems make the natural tuples in this addon unusable as unique
 * indexes, and this class solves both:
 *
 * 1. InnoDB caps an index at 3072 bytes. Under utf8mb4 a `varchar(255)` costs
 *    1020 of them, so a unique across four string columns does not fit. A
 *    prefix index would fit, but it would also declare two genuinely different
 *    values equal — trading a loud migration failure for a silent collision.
 *    A SHA-256 of the whole tuple is 64 characters, indexes every byte of
 *    every column, and cannot conflate two distinct tuples in practice.
 *
 * 2. SQL uniques do not constrain NULL. `unique(brand_id, user_id,
 *    contact_uuid, type, channel)` therefore enforced nothing at all for
 *    contact recipients, whose `user_id` is NULL — any number of duplicate
 *    rows was permitted. Hashing turns the absence of a value into a definite
 *    one, so the constraint finally covers the rows it was written for.
 *
 * The encoding is length-prefixed, so no combination of values can be
 * rearranged into another ("ab" + "c" and "a" + "bc" hash differently), and
 * NULL carries its own marker so it never collides with the empty string.
 *
 * The format is part of the stored data: changing it invalidates every key
 * already written and needs a migration that recomputes them.
 */
final class UniquenessKey
{
    /**
     * @param  array<int, string|int|null>  $parts
     */
    public static function of(array $parts): string
    {
        $canonical = '';

        foreach ($parts as $part) {
            if ($part === null) {
                $canonical .= '-;';

                continue;
            }

            $value = (string) $part;
            $canonical .= strlen($value).':'.$value.';';
        }

        return hash('sha256', $canonical);
    }
}
