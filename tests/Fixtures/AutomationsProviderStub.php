<?php

/**
 * Test stand-in for the OPTIONAL `goldnead/statamic-automations` addon.
 *
 * This addon couples to automations through one string — the name of its
 * service provider — and never declares a composer dependency on it. Declaring
 * the class here is what lets the "installed but too old" and "installed with
 * the screen" cases be told apart in a repository that does not vendor it.
 *
 * The declaration is guarded: if the real package is ever installed alongside,
 * this is skipped and the real class is used.
 */

namespace Goldnead\StatamicAutomations;

if (! class_exists(ServiceProvider::class)) {
    class ServiceProvider {}
}
