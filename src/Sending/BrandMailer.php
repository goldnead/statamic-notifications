<?php

namespace Goldnead\Notifications\Sending;

use Goldnead\BrandContext\Sending\BrandMailer as BrandContextMailer;
use Goldnead\Notifications\Contracts\SenderIdentityResolver;

/**
 * The one door every mail in this package leaves through.
 *
 * The mechanism is {@see BrandContextMailer}: values on the message, never
 * state in the config, a refusal as a return value rather than an exception.
 * This subclass only narrows which resolver gets injected, so that a host
 * rebinding statamic-notifications's {@see SenderIdentityResolver} is answered here and
 * nowhere else.
 */
class BrandMailer extends BrandContextMailer
{
    public function __construct(SenderIdentityResolver $identities)
    {
        parent::__construct($identities);
    }
}
