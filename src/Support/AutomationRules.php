<?php

namespace Goldnead\Notifications\Support;

use Illuminate\Support\Facades\Route;

/**
 * Everything this addon knows about `goldnead/statamic-automations`.
 *
 * The automations addon has a screen that reads a two-node automation as a
 * sentence — "when a form is submitted, send the thank-you mail to the sender".
 * That is what somebody looking for transactional mail rules is looking for,
 * and they look for it here, in the addon whose name says notifications. So
 * this addon offers the way there.
 *
 * **A way there, and nothing else.** No trigger, no listener, no send path of
 * its own. If both addons could turn an event into a mail, "why did this mail
 * go out" would have two possible answers and no way to tell them apart from
 * the outside. The wiring lives in automations, in one place, and this is a
 * signpost.
 *
 * **Two conditions, not one.** The class says the addon is installed; the route
 * says the installed version actually has the screen (it arrived in automations
 * 1.11). Without the second check an older install would get a nav item leading
 * to a 404 — worse than no nav item, because it looks like a broken feature
 * rather than an absent one.
 */
class AutomationRules
{
    /**
     * The automations service provider, as a string.
     *
     * Deliberately not `::class`: that would name a class this package does not
     * require and static analysis has no way to resolve. The string is the
     * whole coupling, and it is one line.
     */
    public const PROVIDER = 'Goldnead\\StatamicAutomations\\ServiceProvider';

    /** The rules screen, under Statamic's CP route name prefix. */
    public const ROUTE = 'statamic.cp.statamic-automations.rules.index';

    /** The name the CP navigation links by, without the prefix Statamic adds. */
    public const NAV_ROUTE = 'statamic-automations.rules.index';

    public static function available(): bool
    {
        return class_exists(self::PROVIDER) && Route::has(self::ROUTE);
    }
}
