<?php

use Illuminate\Support\Facades\Route;

/**
 * Route parameter names are one namespace shared by the whole application.
 *
 * `Route::bind()` is registered on the router, not on a package. A binding an
 * addon registers for `{rule}` applies to every route with a `{rule}` parameter
 * in every other addon installed next to it. Nothing warns about that, nothing
 * logs it, and the losing route does not fail loudly — it resolves its id
 * against a repository that has never heard of it and returns 404.
 *
 * That is not hypothetical. goldnead/statamic-leadhub 1.8.0 shipped
 * `/scoring/{rule}`; goldnead/statamic-webhook-manager binds `rule` to its own
 * rule repository. On the Hub, which has both, editing or deleting a scoring
 * rule did nothing at all, and said nothing at all. LeadHub's own suite was
 * green throughout, for two reasons that are both structural:
 *
 *   1. The sibling addon is not installed in the addon's own test bed, so the
 *      binding it registers does not exist there.
 *   2. That test bed mounted the CP routes without `SubstituteBindings`, so no
 *      `Route::bind()` had any effect in tests even when one was registered.
 *
 * This addon's bed mounts the CP routes through the `web` middleware group,
 * which already carries SubstituteBindings. The first test below pins that, so
 * the property cannot be lost quietly if the group is ever narrowed; the other
 * two check the parameter names themselves.
 *
 * What this file cannot do: a collision only exists once two packages are
 * installed together, and a package cannot see its siblings from inside its own
 * suite. The reserved list below is a snapshot maintained by hand, and it will
 * not catch an addon that starts binding a name nobody binds today — `{id}`,
 * this addon's only parameter, is precisely such a name. That is why the third
 * test exists: it does not know the future either, but it forces any new
 * generic parameter to be a decision somebody wrote down.
 */

/**
 * Every route parameter this addon declares, read from the route files rather
 * than the router, so the check covers routes regardless of how the test bed
 * happens to mount them.
 *
 * Only string literals are scanned. Route files carry example URLs in their
 * comments, and a plain regex over the file text reports those as parameters.
 *
 * @return array<string, list<string>> parameter name => route files using it
 */
function notificationsRouteParameters(): array
{
    $found = [];

    foreach (['cp.php', 'web.php'] as $file) {
        $path = __DIR__.'/../../routes/'.$file;

        if (! is_file($path)) {
            continue;
        }

        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            preg_match_all('/\{([A-Za-z0-9_]+)\??\}/', $token[1], $matches);

            foreach ($matches[1] as $parameter) {
                $found[$parameter][] = $file;
            }
        }
    }

    return array_map('array_unique', $found);
}

it('mounts the CP routes with SubstituteBindings, so a sibling binding is visible here', function (): void {
    // Registered exactly the way a sibling addon's service provider registers
    // one. If the CP route group in tests/TestCase.php stops carrying
    // SubstituteBindings, this callback is never invoked and the request
    // resolves normally — the state every bed in this family was in when the
    // LeadHub defect shipped.
    Route::bind('id', function ($value) {
        abort(418, 'sibling binding reached');
    });

    $this->get(cp_route('notifications.show', 1))->assertStatus(418);
});

it('uses no route parameter that another installed package binds application-wide', function (): void {
    // Names bound application-wide by packages this addon is installed beside.
    // Read off the running Hub: statamic/cms registers the CMS entity bindings,
    // and the two sibling addons register theirs in their service providers.
    // Maintained by hand — see the file docblock for what that costs.
    $boundElsewhere = [
        'automation' => 'goldnead/statamic-automations',
        'webhook' => 'goldnead/statamic-webhook-manager',
        'endpoint' => 'goldnead/statamic-webhook-manager',
        'rule' => 'goldnead/statamic-webhook-manager',
        'template' => 'goldnead/statamic-webhook-manager',
        'asset' => 'statamic/cms',
        'asset_container' => 'statamic/cms',
        'collection' => 'statamic/cms',
        'entry' => 'statamic/cms',
        'form' => 'statamic/cms',
        'global' => 'statamic/cms',
        'revision' => 'statamic/cms',
        'site' => 'statamic/cms',
        'taxonomy' => 'statamic/cms',
        'term' => 'statamic/cms',
    ];

    // This addon binds nothing itself, so it may claim none of these names.
    $collisions = [];

    foreach (notificationsRouteParameters() as $parameter => $files) {
        if (isset($boundElsewhere[$parameter])) {
            $collisions[] = sprintf(
                '{%s} in routes/%s is bound application-wide by %s',
                $parameter,
                implode(', routes/', $files),
                $boundElsewhere[$parameter]
            );
        }
    }

    expect($collisions)->toBe([], implode("\n", $collisions));
});

it('records every generic route parameter as a deliberate choice', function (): void {
    // Names generic enough that a sibling addon could plausibly claim one
    // tomorrow. None of these is bound by anything today, so none of them is a
    // defect — the point is that adding a NEW one has to be noticed.
    $generic = [
        'id', 'handle', 'slug', 'name', 'key', 'type', 'item', 'action',
        'user', 'group', 'role', 'status', 'field', 'page', 'token', 'tag',
        'list', 'record', 'model', 'source', 'preset', 'run', 'rule',
        'template', 'webhook', 'endpoint', 'automation',
    ];

    // Already shipped, and renaming it buys nothing on its own: the URL is
    // unchanged either way, and no sibling binds it. If one ever does, the
    // second test above is where it shows up, once the list there is updated.
    $accepted = [
        'id',  // GET /cp/notifications/{id}, the only parameterised route here
    ];

    $unrecorded = array_values(array_diff(
        array_intersect(array_keys(notificationsRouteParameters()), $generic),
        $accepted
    ));

    expect($unrecorded)->toBe([], sprintf(
        'New generic route parameter(s): %s. Either give them a name no sibling '
        .'addon would pick (the {scoringRule} pattern), or add them to $accepted '
        .'here with the reason.',
        implode(', ', $unrecorded)
    ));
});
