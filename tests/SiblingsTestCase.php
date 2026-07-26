<?php

namespace Goldnead\Notifications\Tests;

/**
 * Boots the sibling addons so the bundled sources can be exercised against
 * their real schema, and skips itself when they are not installed.
 */
abstract class SiblingsTestCase extends TestCase
{
    protected function setUp(): void
    {
        if (! class_exists(\Goldnead\Leadhub\Models\Contact::class)) {
            $this->markTestSkipped('goldnead/statamic-leadhub is not installed.');
        }

        parent::setUp();
    }

    protected function getPackageProviders($app): array
    {
        return [
            ...array_slice(parent::getPackageProviders($app), 0, 3),
            \Goldnead\Leadhub\ServiceProvider::class,
            \Goldnead\Notifications\ServiceProvider::class,
        ];
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../vendor/goldnead/statamic-leadhub/database/migrations');
    }
}
