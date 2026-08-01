<?php

namespace Goldnead\Notifications\Tests;

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\IdentityContracts\Facades\IdentityContext;
use Goldnead\Notifications\Facades\Notifications;
use Goldnead\Notifications\ServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Statamic\Providers\StatamicServiceProvider;
use Statamic\Statamic;
use Statamic\Testing\AddonTestCase;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

abstract class TestCase extends AddonTestCase
{
    use PreventsSavingStacheItemsToDisk;
    use RefreshDatabase;

    protected string $addonServiceProvider = ServiceProvider::class;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate')->run();

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
            \Inertia\ServiceProvider::class,
            \Goldnead\BrandContext\ServiceProvider::class,
            \Goldnead\IdentityContracts\ServiceProvider::class,
            \Goldnead\Suppression\ServiceProvider::class,
            ServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app): array
    {
        return [
            'Statamic' => Statamic::class,
            'Notifications' => Notifications::class,
            'BrandContext' => BrandContext::class,
            'IdentityContext' => IdentityContext::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', $this->connectionConfig());

        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('statamic.users.repository', 'file');

        // The CP routes run through core's real middleware stack here, and
        // CountUsers refuses a second user without Pro. The addon has nothing
        // to do with editions; keeping Pro on stops that from masking a
        // genuine failure.
        $app['config']->set('statamic.editions.pro', true);
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
    protected function connectionConfig(): array
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
        // routes/cp.php is no longer mounted by hand: with a manifest in place
        // the base provider flushes it into core's authenticated CP group, so
        // the CP tests exercise the real middleware stack.
        $this->mountStandInSiblingRoutes($router);
    }

    /**
     * Stand-ins for the routes of a sibling addon installed next to this one.
     *
     * They belong to the bed rather than to the test that reads them because a
     * sibling registers its routes the same way this addon does: at boot, and
     * therefore ahead of Statamic's `{segments?}` frontend catch-all. A route
     * added later — from inside a test body — is shadowed by that catch-all and
     * answers 404 no matter what the bindings do, which would make the check
     * pass for the wrong reason.
     *
     * Each one does nothing but echo its own parameter. If this addon ever
     * binds a name they use, the echo stops happening: the binder resolves the
     * value against a repository here first, finds nothing, and aborts 404 —
     * precisely what LeadHub's delete button did.
     *
     * @see \Goldnead\\Notifications\\Tests\Feature\RouteParameterCollisionTest
     */
    protected function mountStandInSiblingRoutes($router): void
    {
        $router->middleware(SubstituteBindings::class)
            ->group(function ($router) {
                foreach (static::NAMES_A_SIBLING_MIGHT_USE as $name) {
                    $router->get(
                        'sibling-probe/'.$name.'/{'.$name.'}',
                        fn ($value) => (string) $value
                    );
                }
            });
    }

    /**
     * Generic names a sibling addon could plausibly put in one of its own
     * routes. None of them is bound by anything in this application today —
     * `rule` and `template` were claimed by statamic-webhook-manager until its
     * 1.7.0 and `automation` by statamic-automations until its 1.6.0. They are
     * here because a sibling reaching for one is what the rule prevents.
     *
     * @var list<string>
     */
    public const NAMES_A_SIBLING_MIGHT_USE = [
        'automation', 'rule', 'template', 'webhook', 'endpoint', 'handle', 'id', 'slug', 'record',
    ];

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
