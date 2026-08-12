<?php

namespace Goldnead\Notifications\Sending;

use Goldnead\Notifications\Contracts\SenderIdentityResolver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use LogicException;

/**
 * The one door every mail in this package leaves through.
 *
 * Before this class the two send paths — the immediate `MailChannel` and the
 * digest command — each called `Mail::to(...)->send(...)`. That is the
 * process-wide default mailer with the process-wide From, identically for every
 * brand. The failure is not cosmetic: a relay that verifies sending domains per
 * account replaces a From it does not own, so in a run across several brands
 * the second brand's mail arrives under the first brand's name and address.
 *
 * **Values, not config.** The identity is applied to the message
 * (`Mailable::from()`, `Mail::mailer($name)`) and never written to config. See
 * {@see SenderIdentity} for why a scoped config override with a `finally` does
 * not work here.
 *
 * **Locale travels on the mailable**, not on the app. `Mailable::locale()` is
 * what Laravel wraps the whole render in; `app()->setLocale()` around the send
 * would also change the locale of anything else the render triggers.
 *
 * **A refusal is a return value, not an exception.** A digest run has to skip
 * one brand and carry on with the next, and it has to know before it stamps.
 * Every refusal is logged at error level, throttled per brand so a fan-out
 * cannot bury it.
 */
class BrandMailer
{
    public function __construct(protected SenderIdentityResolver $identities) {}

    /**
     * May this brand send at all?
     *
     * For callers that must decide before they act rather than after they
     * failed. The digest is the reason it exists: `DigestBuilder::markSent()`
     * stamps `digested_at` on every collected item *before* the mail goes out,
     * so a brand that cannot send has to be skipped in front of the recipient
     * loop. Discovering it at the send would mean every recipient's week of
     * items is marked delivered and never resurfaces.
     */
    public function maySend(?int $brandId): bool
    {
        $identity = $this->identities->resolve($brandId);

        if ($identity->maySend()) {
            return true;
        }

        $this->sayRefused($brandId, $identity);

        return false;
    }

    /**
     * Send one mailable as the given brand.
     *
     * @param  int|null  $brandId  null = the brand in context, if any.
     * @return bool whether it went out
     */
    public function send(?int $brandId, string $to, ?string $toName, Mailable $mailable): bool
    {
        $this->refuseQueued($mailable);

        $identity = $this->identities->resolve($brandId);

        if (! $identity->maySend()) {
            $this->sayRefused($brandId, $identity);

            return false;
        }

        // An explicit locale on the mailable wins: the caller knew something
        // the brand row does not.
        if ($identity->locale !== null && ! $mailable->locale) {
            $mailable->locale($identity->locale);
        }

        // Null means the brand declared nothing, so whatever the mailable's own
        // `build()` decides — and behind it `config('mail.from')` — stays in
        // charge. Setting the config value here instead would silently take
        // precedence over a mailable that sets its own address.
        if ($identity->fromAddress !== null) {
            $mailable->from($identity->fromAddress, $identity->fromName);
        }

        // The recipient goes on the mailable rather than through
        // `Mail::mailer(...)->to(...)`: the `Mailer` contract's `to()` takes no
        // name, and dropping the recipient's name to satisfy an interface would
        // be a visible regression in every mail this sends.
        $mailable->to($to, $toName);

        Mail::mailer($identity->mailer)->send($mailable);

        return true;
    }

    /**
     * Send a message assembled by hand, as the given brand.
     *
     * The path for pre-rendered HTML or plain text that has no mailable. The
     * identity is applied before the callback runs, so a callback that only
     * adds recipients and a subject gets the brand's From for free.
     *
     * The callback receives the identity as well, because a caller may have a
     * From of its own to fall back on when the brand declared none — see the
     * automations `send_email` node. Whatever it does, it must not overwrite a
     * non-null `$identity->fromAddress`: that is the brand identity, and
     * replacing it is the bug this class exists to prevent.
     *
     * @param  callable(Message, SenderIdentity): void  $build
     * @return bool whether it went out
     */
    public function sendRaw(?int $brandId, ?string $html, ?string $text, callable $build): bool
    {
        $identity = $this->identities->resolve($brandId);

        if (! $identity->maySend()) {
            $this->sayRefused($brandId, $identity);

            return false;
        }

        $mailer = Mail::mailer($identity->mailer);

        $assemble = function (Message $message) use ($identity, $build): void {
            if ($identity->fromAddress !== null) {
                $message->from($identity->fromAddress, $identity->fromName);
            }

            $build($message, $identity);
        };

        $html !== null
            ? $mailer->html($html, $assemble)
            : $mailer->raw((string) $text, $assemble);

        return true;
    }

    /**
     * A queued mailable would leave this method and be built later, in another
     * process, with none of this in place — the identity would be gone and
     * nothing would turn red. Callers that need the queue queue the surrounding
     * job and carry the brand id on it.
     */
    protected function refuseQueued(Mailable $mailable): void
    {
        if ($mailable instanceof ShouldQueue) {
            throw new LogicException(
                'A ShouldQueue mailable cannot carry a brand sender identity: it is built after this '
                .'send returns, when the identity is no longer in place. Queue the surrounding job '
                .'instead and carry the brand id on it. ('.$mailable::class.')'
            );
        }
    }

    protected function sayRefused(?int $brandId, SenderIdentity $identity): void
    {
        if (! SaidRecently::shouldSay('refused:'.($brandId ?? 'current').':'.$identity->refusal)) {
            return;
        }

        Log::error($identity->refusal, ['brand_id' => $brandId]);
    }
}
