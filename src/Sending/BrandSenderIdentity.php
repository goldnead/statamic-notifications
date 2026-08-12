<?php

namespace Goldnead\Notifications\Sending;

use Goldnead\BrandContext\Sending\BrandSenderIdentity as BrandContextSenderIdentity;
use Goldnead\Notifications\Contracts\SenderIdentityResolver;

/**
 * Reads the sender identity out of `brands.settings.mail`.
 *
 * The whole implementation is {@see BrandContextSenderIdentity} in
 * `goldnead/statamic-brand-context`, which is where it belongs: which address
 * and which transport a brand sends under is a property of the brand, not of
 * whichever addon happens to be posting. This class exists only so that the
 * default answer for statamic-notifications can be swapped without touching the default for
 * every other addon on the host.
 */
class BrandSenderIdentity extends BrandContextSenderIdentity implements SenderIdentityResolver {}
