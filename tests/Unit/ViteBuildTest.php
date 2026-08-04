<?php

use Goldnead\Notifications\ServiceProvider;

/**
 * The committed bundle, checked against the two things that decide whether it
 * ever loads.
 *
 * A Marketplace or Composer install runs no npm, so `resources/dist/build` is
 * what a customer gets. Nothing in the PHP suite exercises it, and nothing
 * about a wrong entry name or a missing manifest looks broken until a Control
 * Panel renders an empty page.
 */
function viteConfig(): array
{
    $property = new ReflectionProperty(ServiceProvider::class, 'vite');

    return $property->getDefaultValue();
}

function packagePath(string $path = ''): string
{
    return rtrim(dirname(__DIR__, 2).'/'.ltrim($path, '/'), '/');
}

it('ships a manifest where the provider says the build lives', function (): void {
    $config = viteConfig();

    expect($config['publicDirectory'])->toBe('resources/dist');
    expect(packagePath($config['publicDirectory'].'/build/manifest.json'))->toBeReadableFile();
});

it('builds exactly the entry points the provider declares', function (): void {
    $config = viteConfig();

    $manifest = json_decode(
        (string) file_get_contents(packagePath($config['publicDirectory'].'/build/manifest.json')),
        associative: true
    );

    // laravel-vite-plugin keys the manifest by source path, and Statamic's Vite
    // tag looks each declared input up by exactly that key. An input renamed on
    // one side only resolves to nothing, silently.
    expect(array_keys($manifest))->toEqualCanonicalizing($config['input']);

    foreach ($manifest as $entry) {
        expect(packagePath($config['publicDirectory'].'/build/'.$entry['file']))->toBeReadableFile();
    }
});

it('registers both Inertia pages the controller renders', function (): void {
    $config = viteConfig();

    $manifest = json_decode(
        (string) file_get_contents(packagePath($config['publicDirectory'].'/build/manifest.json')),
        associative: true
    );

    $bundle = (string) file_get_contents(
        packagePath($config['publicDirectory'].'/build/'.$manifest['resources/js/cp.js']['file'])
    );

    // The page names are a contract between Inertia::render() in PHP and
    // Statamic.$inertia.register() in JS. Renaming one side leaves a blank
    // screen and no error anywhere.
    expect($bundle)->toContain('notifications::Index');
    expect($bundle)->toContain('notifications::Show');
});
