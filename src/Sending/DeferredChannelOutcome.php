<?php

namespace Goldnead\Notifications\Sending;

use Goldnead\Notifications\Contracts\DeferredChannel;

/**
 * What one deferred send attempt produced.
 *
 * A channel that participates in deferred delivery has to be able to *say*
 * what happened — the legacy `Channel::send(): void` cannot, which is exactly
 * why a channel without {@see DeferredChannel}
 * never gets a delivery row and is never counted as accepted.
 *
 * The three statuses, and what the dispatcher does with them:
 *
 * - `accepted` — the transport (or, for in-app, the durable row itself) has
 *   the notification. Terminal, `accepted_at` is written.
 * - `skipped` — deliberately not sent, and retrying would not change that:
 *   no address, a suppressed mailbox, a preference switched off. Terminal.
 * - `retry` — not sent *yet*, and a later attempt may succeed: the gate could
 *   not answer, the sender identity was unusable. Counts as a failed attempt
 *   and enters the backoff schedule.
 *
 * Expected outcomes travel in this object. An exception out of
 * `sendDeferred()` is treated as a transport error (reported, then retried),
 * so channels only throw for the genuinely unexpected.
 *
 * The outcome codes are a fixed vocabulary shared by every channel so an
 * operator can group on them; free text has no place in `outcome_code`, which
 * is stored long after whatever produced it.
 */
final readonly class DeferredChannelOutcome
{
    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_RETRY = 'retry';

    /** The mail system took the mail. Not inbox delivery — see the README. */
    public const CODE_ACCEPTED_BY_TRANSPORT = 'accepted_by_transport';

    /**
     * The in-app delivery is the notification row itself, durable since it
     * was written; the broadcast nudge on top is best-effort.
     */
    public const CODE_IN_APP_DURABLE = 'in_app_durable';

    /** The resolved recipient carries no address to mail. */
    public const CODE_NO_ADDRESS = 'no_address';

    /** The suppression gate says this mailbox must not be written to. */
    public const CODE_SUPPRESSED = 'suppressed';

    /** The suppression gate could not answer; never permission to send. */
    public const CODE_SUPPRESSION_UNAVAILABLE = 'suppression_unavailable';

    /** The send attempt itself failed. */
    public const CODE_TRANSPORT_ERROR = 'transport_error';

    /** The brand mailer refused: no usable sender identity. */
    public const CODE_SENDER_REFUSED = 'sender_refused';

    private function __construct(
        public string $status,
        public string $outcomeCode,
    ) {}

    public static function accepted(string $outcomeCode): self
    {
        return new self(self::STATUS_ACCEPTED, $outcomeCode);
    }

    public static function skipped(string $outcomeCode): self
    {
        return new self(self::STATUS_SKIPPED, $outcomeCode);
    }

    public static function retry(string $outcomeCode): self
    {
        return new self(self::STATUS_RETRY, $outcomeCode);
    }

    public function isAccepted(): bool
    {
        return $this->status === self::STATUS_ACCEPTED;
    }

    public function isSkipped(): bool
    {
        return $this->status === self::STATUS_SKIPPED;
    }

    public function isRetry(): bool
    {
        return $this->status === self::STATUS_RETRY;
    }
}
