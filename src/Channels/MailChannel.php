<?php

namespace Goldnead\Notifications\Channels;

use Goldnead\IdentityContracts\Identity;
use Goldnead\Notifications\Contracts\Channel;
use Goldnead\Notifications\Contracts\DeferredChannel;
use Goldnead\Notifications\Mail\NotificationMail;
use Goldnead\Notifications\Models\NotificationItem;
use Goldnead\Notifications\Sending\BrandMailer;
use Goldnead\Notifications\Sending\DeferredChannelOutcome;
use Goldnead\Notifications\Sending\SentSnapshot;
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
 *
 * Since deferred delivery this class speaks both dialects: the legacy void
 * {@see send()} for the immediate path, and {@see sendDeferred()} for the
 * durable path, where the differences are policy, not plumbing — the deferred
 * path resolves no fallback address and must distinguish "the gate could not
 * answer" (retry) from "this mailbox is blocked" (skip). The physical send is
 * one private method so the two paths cannot drift apart.
 */
class MailChannel implements Channel, DeferredChannel
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

        $this->mail($item, $recipient, $address);
    }

    public function sendDeferred(NotificationItem $item, Identity $recipient): DeferredChannelOutcome
    {
        // No fallback to the address on the item. The recipient resolver was
        // asked precisely so the mail goes where this person is reachable
        // *now*; silently mailing the address they had when the fact happened
        // would undo the reason the resolver exists.
        $address = $recipient->email;

        if ($address === null || $address === '') {
            return DeferredChannelOutcome::skipped(DeferredChannelOutcome::CODE_NO_ADDRESS);
        }

        try {
            $suppressed = $this->gate->isSuppressed($address);
        } catch (SuppressionCheckFailed $e) {
            // Fails closed here too, but loudly upwards: the row is retried,
            // never accepted. "The list could not be read" is not permission
            // to write to a mailbox that may have complained, and it is also
            // not a reason to give up on the notification.
            Log::warning(
                'Notifications withheld a deferred mail: the suppression gate could not be queried, '
                .'so whether this address is blocked is unknown. The delivery will be retried. '
                .$e->getMessage()
            );

            return DeferredChannelOutcome::retry(DeferredChannelOutcome::CODE_SUPPRESSION_UNAVAILABLE);
        }

        if ($suppressed) {
            return DeferredChannelOutcome::skipped(DeferredChannelOutcome::CODE_SUPPRESSED);
        }

        return $this->mail($item, $recipient, $address);
    }

    /**
     * The one physical send, shared by both paths.
     *
     * Was hier rausgeht, festgehalten — vor der Uebergabe an den Mailer, wie
     * der Vertrag der Snapshot-Schicht es verlangt. Aufgezeichnet wird das
     * Layout mit Platzhaltern, nie der Text dieses einen Empfaengers; siehe
     * SentSnapshot. Der Zeiger auf die Zeile wird erst gesetzt, wenn die Mail
     * wirklich rausging.
     */
    protected function mail(NotificationItem $item, Identity $recipient, string $address): DeferredChannelOutcome
    {
        $rendered = $this->types->get($item->type)->render($item);

        $snapshotId = SentSnapshot::record($item, $rendered);

        // The brand comes off the row, not out of the ambient context. Today
        // the two agree — `NotificationManager` dispatches inside the brand it
        // just wrote — but "today they agree" is not a guarantee: a host that
        // calls this channel from a job of its own, or an installation running
        // `brand-context.fail_mode=open`, would resolve no brand at all and
        // fall back to the config identity without a word. The row knows.
        $wentOut = $this->mailer()->send(
            (int) $item->brand_id,
            $address,
            $recipient->name,
            new NotificationMail($item, $rendered),
        );

        if (! $wentOut) {
            // The mailer refused (no usable sender identity for this brand).
            // Nothing left the building, so the snapshot pointer stays unset:
            // a pointer on the row makes the detail page claim a mail that
            // never was. Retried, not skipped — a fixed brand row lets the
            // next attempt through.
            return DeferredChannelOutcome::retry(DeferredChannelOutcome::CODE_SENDER_REFUSED);
        }

        // Erst jetzt. Ein Zeiger, der vor dem Versand gesetzt wird, laesst eine
        // Mail, die nie das Haus verliess, auf der Detailseite wie eine
        // zugestellte aussehen.
        if ($snapshotId !== null) {
            $item->forceFill(['email_template_snapshot_id' => $snapshotId])->save();
        }

        return DeferredChannelOutcome::accepted(DeferredChannelOutcome::CODE_ACCEPTED_BY_TRANSPORT);
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
     *
     * Used by the immediate path; `sendDeferred()` asks the gate directly
     * because it must tell "blocked" and "unanswerable" apart — this method
     * collapses both into one closed answer, which is right for a send that
     * will never be retried and wrong for one that will.
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
