<?php

namespace Goldnead\Notifications\Sending;

/**
 * A throttle for log lines that would otherwise be written once per recipient.
 *
 * Sender-identity problems are per brand, not per message, but they are
 * discovered per message. A fan-out over a list writes the same sentence
 * thousands of times, which is how a real warning gets scrolled past. So each
 * subject is said once per window.
 *
 * Re-armed after the window rather than silenced for the life of the process:
 * a `queue:work` runs for days, and every one of these subjects means mail is
 * not going out. A problem that lasts a week has to be visible on the day
 * somebody looks.
 */
final class SaidRecently
{
    /** @var array<string, float> */
    private static array $at = [];

    /**
     * Long enough to collapse a fan-out, short enough that a long-running
     * worker keeps reporting.
     */
    public const WINDOW_SECONDS = 300;

    public static function shouldSay(string $key): bool
    {
        $now = microtime(true);

        if (isset(self::$at[$key]) && ($now - self::$at[$key]) < self::WINDOW_SECONDS) {
            return false;
        }

        self::$at[$key] = $now;

        return true;
    }

    /**
     * Forget everything, so the next occurrence is reported again. The suite
     * needs it because this state outlives a test and brand ids are recycled.
     */
    public static function forget(): void
    {
        self::$at = [];
    }
}
