<?php

/*
 * A PHPDoc correction for one upstream property, nothing else.
 *
 * `Statamic\Providers\AddonServiceProvider::$vite` is annotated `@var
 * list<string>` in statamic/cms 6.x, but that is not the shape the class
 * reads: `bootVite()` hands the property to `registerVite()`, which
 * destructures `input`, `publicDirectory` and `hotFile` out of it, and every
 * v6 addon (and core's own documentation) therefore sets an associative array.
 *
 * Without this stub, a correct `$vite` on our provider is an error at level 5
 * — either suppressed with an ignore comment or parked in the baseline, both
 * of which record a defect in this repo for a docblock in another one. A stub
 * file is the mechanism PHPStan itself points at for exactly this case, and it
 * stops applying the moment the upstream annotation is fixed.
 *
 * Stub files replace PHPDoc only; every member not restated here keeps coming
 * from the real class. The real parent class is deliberately not repeated in
 * the `extends` clause: a stub is not analysed against the autoloader, so
 * naming it would only produce a class.notFound of its own.
 */

namespace Statamic\Providers;

abstract class AddonServiceProvider
{
    /**
     * @var array{input: list<string>, publicDirectory?: string, hotFile?: string}|null
     */
    protected $vite = null;
}
