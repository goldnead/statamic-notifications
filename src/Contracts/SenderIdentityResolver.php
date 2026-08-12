<?php

namespace Goldnead\Notifications\Contracts;

use Goldnead\Notifications\Sending\BrandSenderIdentity;
use Goldnead\Notifications\Sending\SenderIdentity;

/**
 * Answers "who does brand N send as, and over which mailer".
 *
 * The extension point exists so a host application can decide this its own way
 * without the addon having to know the host. The bundled implementation reads
 * `brands.settings.mail` (see {@see BrandSenderIdentity}), which is the same
 * place the sibling addons read; a host that keeps sender identities somewhere
 * else rebinds this interface in its own provider:
 *
 *     $this->app->bind(SenderIdentityResolver::class, MyResolver::class);
 *
 * An implementation must never throw. "I cannot answer" has two legitimate
 * shapes and neither of them is an exception:
 *
 * - {@see SenderIdentity::fromConfig()} — nothing is known about this brand, so
 *   nothing changes and the mail goes out exactly as it did before this
 *   interface existed. This is what a single-brand install always gets.
 * - {@see SenderIdentity::refusing()} — the brand *declared* a mail identity and
 *   it is not usable. Then no mail goes out at all. Falling back to the
 *   configured identity here would send this brand's mail from the address the
 *   global credentials belong to, which is the exact failure this contract
 *   exists to prevent, with the added charm of being silent.
 *
 * The difference between the two is "said nothing" versus "said something
 * broken", and it is the whole reason the refusal is a separate state rather
 * than a null.
 */
interface SenderIdentityResolver
{
    /**
     * @param  int|null  $brandId  The brand the mail belongs to; null means
     *                             "the brand currently in context, if any".
     */
    public function resolve(?int $brandId): SenderIdentity;
}
