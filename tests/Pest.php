<?php

use Goldnead\Notifications\Tests\MigrationPathTestCase;
use Goldnead\Notifications\Tests\SiblingsTestCase;
use Goldnead\Notifications\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');
uses(SiblingsTestCase::class)->in('Integration');

// The migration path gets a bed of its own, on a connection of its own: these
// tests install an earlier release and migrate forward, which cannot happen
// inside the transaction RefreshDatabase holds open for everything else.
uses(MigrationPathTestCase::class)->in('Migrations');
