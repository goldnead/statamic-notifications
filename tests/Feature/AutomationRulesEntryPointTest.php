<?php

use Goldnead\Notifications\Support\AutomationRules;
use Illuminate\Support\Facades\Route;

/**
 * The way to the mail rules, and the thing this addon must never grow.
 *
 * Transactional mail rules ("when a form is submitted, send the thank-you mail")
 * are configured in `goldnead/statamic-automations`. Somebody looking for them
 * looks in the addon called Notifications first, so this addon points there.
 *
 * **A signpost, not a second implementation.** If both addons could turn an
 * event into a mail, "why did this mail go out" would have two possible answers
 * and no way to tell them apart from outside. The last test in this file is the
 * one that matters most: it fails the moment this addon grows a send path of
 * its own that fires off an event.
 */
it('offers no entry point when the automations addon is not installed', function (): void {
    // Nothing is stubbed here on purpose: this is the plain install.
    expect(class_exists(AutomationRules::PROVIDER))->toBeFalse()
        ->and(AutomationRules::available())->toBeFalse();
});

it('offers no entry point when automations is installed but has no rules screen', function (): void {
    // The screen arrived in automations 1.11. An older install would otherwise
    // get a nav item leading to a 404 — worse than no item, because it reads as
    // a broken feature rather than an absent one.
    require_once __DIR__.'/../Fixtures/AutomationsProviderStub.php';

    expect(class_exists(AutomationRules::PROVIDER))->toBeTrue()
        ->and(Route::has(AutomationRules::ROUTE))->toBeFalse()
        ->and(AutomationRules::available())->toBeFalse();
});

it('points at the automations screen once that screen exists', function (): void {
    require_once __DIR__.'/../Fixtures/AutomationsProviderStub.php';

    Route::get('cp/automations/rules', fn () => 'ok')->name(AutomationRules::ROUTE);
    // `->name()` is set after the route is added, so the collection's name
    // lookup is one step behind until something rebuilds it. In a booted
    // application the first matched request does that; here nothing has been
    // matched yet.
    Route::getRoutes()->refreshNameLookups();

    expect(AutomationRules::available())->toBeTrue();
});

it('wires no event of its own into a send path', function (): void {
    // This addon sends mail only when somebody explicitly notifies (its mail
    // channel) or when a digest is run from the console. It listens to no
    // domain event, and must not start: the event-to-mail wiring belongs in
    // automations, in one place.
    $offenders = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../../src'));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        if (preg_match('/Event::listen\(|\$listen\s*=|Event::subscribe\(/', $source)) {
            $offenders[] = $file->getFilename();
        }
    }

    expect($offenders)->toBe([]);
});
