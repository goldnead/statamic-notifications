<?php

namespace Goldnead\Notifications\Channels;

use Goldnead\IdentityContracts\Identity;
use Goldnead\Notifications\Contracts\Channel;
use Goldnead\Notifications\Mail\NotificationMail;
use Goldnead\Notifications\Models\NotificationItem;
use Goldnead\Notifications\Sending\BrandMailer;
use Goldnead\Notifications\Types\TypeRegistry;
use Goldnead\Suppression\Contracts\Gate as SuppressionGate;
use Goldnead\Suppression\Exceptions\SuppressionCheckFailed;
use Illuminate\Support\Facades\Log;

/**
 * Immediate one-per-notification e-mail. The subject and body come from the
 * type's renderer, so the addon never writes a sentence of its own.
 *
 * The send goes through {@see BrandMailer} rather than the `Mail` facade. Until
 * 12.08.2026 the last line of `send()` read `Mail::to($address)->send(...)`,
 * which is the process-wide default mailer with the process-wide From. In a
 * host serving several brands out of one queue worker that means the second
 * brand's notification leaves under the first brand's address, and a relay that
 * verifies sending domains per account either refuses it or rewrites the From
 * to somebody else's verified identity. In a single-brand install nothing
 * changes: the resolver returns the config identity and this is the same send
 * it always was.
 */
class MailChannel implements Channel
{
    /**
     * `$mailer` is optional so that a host subclassing this channel — which the
     * hub does — keeps working across the upgrade without touching its
     * `parent::__construct()` call.
     */
    public function __construct(
        protected TypeRegistry $types,
        protected SuppressionGate $gate,
        protected ?BrandMailer $mailer = null,
    ) {}

    public function send(NotificationItem $item, Identity $recipient): void
    {
        $address = $recipient->email ?? $item->email;

        if ($address === null || $address === '') {
            return;
        }

        if ($this->isSuppressed($address)) {
            return;
        }

        $rendered = $this->types->get($item->type)->render($item);

        // The brand comes off the row, not out of the ambient context. Today
        // the two agree — `NotificationManager` dispatches inside the brand it
        // just wrote — but "today they agree" is not a guarantee: a host that
        // calls this channel from a job of its own, or an installation running
        // `brand-context.fail_mode=open`, would resolve no brand at all and
        // fall back to the config identity without a word. The row knows.
        $this->mailer()->send(
            (int) $item->brand_id,
            $address,
            $recipient->name,
            new NotificationMail($item, $rendered),
        );
    }

    protected function mailer(): BrandMailer
    {
        return $this->mailer ??= app(BrandMailer::class);
    }

    /**
     * The one thing a channel is allowed to decide for itself.
     *
     * The `Channel` contract says channels never decide *whether* to deliver —
     * the preference resolver has done that before they are called. This is a
     * deliberate exception to that sentence rather than a violation of it, and
     * the distinction is the point of the whole suppression layer.
     *
     * A preference is what the recipient wants. A suppression is what the
     * mailbox can take: a hard bounce means the address is gone, and a complaint
     * means writing to it again carries legal weight. Neither is a preference,
     * and neither may be overridden by a notification type declaring itself
     * `required` — which is exactly what `PreferenceResolver::allows()` does,
     * unconditionally, before it looks at anything stored. So the check cannot
     * live in the preference layer. It sits here, in front of the only line in
     * this class that reaches a mailbox.
     *
     * The persisted row is written either way, in `NotificationManager`. This
     * decides how somebody is reached, never whether the thing happened.
     */
    protected function isSuppressed(string $address): bool
    {
        try {
            return $this->gate->isSuppressed($address);
        } catch (SuppressionCheckFailed $e) {
            // Fails closed, and leaves a line saying so.
            // `NotificationManager::dispatchChannels()` swallows channel
            // exceptions through report(), so throwing from here would stop the
            // send and produce nothing anybody reads.
            Log::warning(
                'Notifications withheld an immediate mail: the suppression gate could not be queried, '
                .'so whether this address is blocked is unknown. '.$e->getMessage()
            );

            return true;
        }
    }
}
