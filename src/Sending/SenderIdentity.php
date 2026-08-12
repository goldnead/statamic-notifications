<?php

namespace Goldnead\Notifications\Sending;

/**
 * Who a mail goes out as, and over which transport.
 *
 * Every value is nullable and every null means "whatever was true before this
 * class existed": the configured mailer, whatever from-address the mailable or
 * the config settles on, the application locale. A single-brand install
 * resolves {@see self::fromConfig()}, in which all four are null and nothing
 * about the send changes — not even the order in which `Mailable::build()` gets
 * to set its own From.
 *
 * The reason this is a value object rather than four `config()` reads at the
 * call site: the from-address and the transport have to agree. A relay that
 * verifies sending domains per account (Scaleway TEM, Postmark, SES with a
 * verified identity) rejects — or silently rewrites — a From it does not own.
 * Resolving them together makes the pair impossible to split by accident.
 *
 * These are values, never side effects. Nothing here writes to config. Laravel
 * reads `mail.from` the first time a mailer name is resolved and burns it into
 * the mailer instance via `alwaysFrom()`; that instance is then cached in the
 * `mail.manager` singleton for the life of the process. An override of
 * `mail.from.*` inside a scoped window therefore *escapes the window*, even
 * with a `finally` that puts the old value back: whichever brand happened to
 * send first leaves its address standing in the cached mailer, and the next
 * message through it that sets no From of its own goes out as that brand. That
 * is the bug. It is not fixable by being careful with config; it is fixable
 * only by putting the sender on the message.
 */
final class SenderIdentity
{
    private function __construct(
        public readonly ?string $mailer = null,
        public readonly ?string $fromAddress = null,
        public readonly ?string $fromName = null,
        public readonly ?string $locale = null,
        public readonly ?string $refusal = null,
    ) {}

    /**
     * The identity as it was before per-brand sending: everything from config.
     *
     * All-null on purpose. `null` mailer is what `Mail::mailer(null)` already
     * means, and a null from-address leaves the mailable's own `from()` — and
     * behind it `config('mail.from')` — exactly where they were. Filling these
     * in with config values would look equivalent and is not: it would take
     * precedence over a `Mailable::build()` that sets its own address.
     */
    public static function fromConfig(): self
    {
        return new self;
    }

    /**
     * An identity a brand declared.
     *
     * **A named transport without an address is refused here, not built.** The
     * bundled resolver never produces that combination, but a host is free to
     * bind its own, and this constructor is the only thing standing between a
     * host resolver's typo and the exact failure the class exists to prevent:
     * one brand's relay account carrying another brand's From. The invariant
     * belongs where it cannot be forgotten rather than in whichever resolver
     * happens to be bound.
     *
     * The other direction stays legitimate and silent: an address without a
     * mailer means "my domain is verified in the account the app already
     * uses", which is the ordinary case for the brand the global credentials
     * belong to.
     */
    public static function of(
        ?string $mailer,
        ?string $fromAddress,
        ?string $fromName,
        ?string $locale = null,
    ): self {
        if ($mailer !== null && $fromAddress === null) {
            return self::refusing(sprintf(
                'A sender identity names the mailer [%s] but no from-address. Nothing was sent: that '
                .'pairs one brand\'s transport with the host-wide From, which a relay verifying '
                .'sending domains per account refuses — or delivers under somebody else\'s name.',
                $mailer,
            ));
        }

        return new self($mailer, $fromAddress, $fromName, $locale);
    }

    /**
     * This brand may not send at all, and here is the sentence saying why.
     *
     * Not an exception, because the caller decides what "do not send" costs:
     * a digest run skips one brand and continues with the next, a single
     * transactional send reports a failure. Both need the reason, neither
     * needs a stack trace.
     */
    public static function refusing(string $reason): self
    {
        return new self(refusal: $reason);
    }

    public function maySend(): bool
    {
        return $this->refusal === null;
    }
}
