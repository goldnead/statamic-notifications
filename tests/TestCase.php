<?php

namespace Goldnead\Notifications\Tests;

use Goldnead\BrandContext\Models\Brand;
use Goldnead\Notifications\ServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;
use Statamic\Providers\StatamicServiceProvider;
use Statamic\Statamic;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Testbench never fires Statamic::booted callbacks, so bootAddon() —
        // migrations, views, nav, permissions, channel, sources — must be
        // invoked by hand.
        $this->app->getProvider(ServiceProvider::class)?->bootAddon();

        $this->artisan('migrate')->run();

        $this->withoutVite();
        $this->giveTestbenchAComposerLock();

        app('brand-context')->forget();
        app('identity-context')->forget();
        app('notifications')->types()->forget();
        app('notifications')->sources()->forget();
    }

    protected function getPackageProviders($app): array
    {
        return [
            StatamicServiceProvider::class,
            \Goldnead\BrandContext\ServiceProvider::class,
            \Goldnead\IdentityContracts\ServiceProvider::class,
            ServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app): array
    {
        return [
            'Statamic' => Statamic::class,
            'Notifications' => \Goldnead\Notifications\Facades\Notifications::class,
            'BrandContext' => \Goldnead\BrandContext\Facades\BrandContext::class,
            'IdentityContext' => \Goldnead\IdentityContracts\Facades\IdentityContext::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', $this->testingConnection());

        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('statamic.users.repository', 'file');
        $app['config']->set('brand-context.multi_brand', false);
        $app['config']->set('mail.default', 'array');
        $app['config']->set('queue.default', 'sync');
    }

    /**
     * In-memory SQLite by default, so the suite keeps running anywhere with no
     * setup. Set `DB_DRIVER=mysql` to point the identical suite at a real MySQL
     * server instead — see phpunit.mysql.xml.
     *
     * SQLite is not a substitute here. It has no InnoDB key-length limit, no
     * utf8mb4 byte arithmetic and no fixed column widths, which is precisely
     * why a fully green suite let an unbuildable index reach production.
     */
    protected function testingConnection(): array
    {
        if (env('DB_DRIVER', 'sqlite') !== 'mysql') {
            return [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ];
        }

        return [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'notifications_test'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
        ];
    }

    protected function defineRoutes($router): void
    {
        $router->middleware(['web'])
            ->prefix('cp')
            ->name('statamic.cp.')
            ->group(__DIR__.'/../routes/cp.php');
    }

    /**
     * Statamic's CP layout resolves its own version from base_path('composer.lock')
     * and throws without one. The testbench app has none, so lend it ours.
     */
    protected function giveTestbenchAComposerLock(): void
    {
        $target = base_path('composer.lock');

        if (file_exists($target)) {
            return;
        }

        $source = __DIR__.'/../composer.lock';

        if (file_exists($source)) {
            @copy($source, $target);
        }
    }

    protected function enableMultiBrand(): void
    {
        config()->set('brand-context.multi_brand', true);
        app('brand-context')->forget();
    }

    protected function makeBrand(string $handle): Brand
    {
        return Brand::create(['handle' => $handle, 'name' => ucfirst($handle)]);
    }
}
