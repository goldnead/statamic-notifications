<?php

namespace Goldnead\Notifications;

use Goldnead\Notifications\Channels\ChannelRegistry;
use Goldnead\Notifications\Channels\LaravelChannelAdapter;
use Goldnead\Notifications\Console\SendDigestsCommand;
use Goldnead\Notifications\Contracts\RecipientDirectory;
use Goldnead\Notifications\Digest\DigestBuilder;
use Goldnead\Notifications\Digest\PendingItemRecipientDirectory;
use Goldnead\Notifications\Digest\SourceRegistry;
use Goldnead\Notifications\Preferences\PreferenceResolver;
use Goldnead\Notifications\Realtime\BroadcastAdapter;
use Goldnead\Notifications\Sources\LeadHubSource;
use Goldnead\Notifications\Types\TypeRegistry;
use Illuminate\Support\Facades\Notification as LaravelNotification;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Permission;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    protected $routes = [
        'cp' => __DIR__.'/../routes/cp.php',
    ];

    protected $commands = [
        SendDigestsCommand::class,
    ];

    public function register(): void
    {
        parent::register();

        $this->mergeConfigFrom(__DIR__.'/../config/notifications.php', 'notifications');

        // Registered against the resolving translator: nav and permission
        // labels are built before bootAddon() runs.
        $this->app->resolving('translator', function ($translator): void {
            $translator->addNamespace('notifications', __DIR__.'/../resources/lang');
        });

        $this->app->singleton(TypeRegistry::class);
        $this->app->singleton(SourceRegistry::class);
        $this->app->singleton(BroadcastAdapter::class);

        $this->app->singleton(ChannelRegistry::class, function (): ChannelRegistry {
            $registry = new ChannelRegistry;

            foreach ((array) config('notifications.channels', []) as $handle => $channel) {
                $registry->register($handle, $channel);
            }

            return $registry;
        });

        $this->app->singleton(PreferenceResolver::class, fn ($app) => new PreferenceResolver(
            $app->make(TypeRegistry::class),
        ));

        $this->app->singleton(DigestBuilder::class, fn ($app) => new DigestBuilder(
            $app->make(SourceRegistry::class),
        ));

        $this->app->bind(RecipientDirectory::class, PendingItemRecipientDirectory::class);

        $this->app->singleton('notifications', fn ($app) => new NotificationManager(
            $app->make(TypeRegistry::class),
            $app->make(ChannelRegistry::class),
            $app->make(PreferenceResolver::class),
            $app->make(SourceRegistry::class),
        ));
        $this->app->alias('notifications', NotificationManager::class);
    }

    public function bootAddon(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'notifications');

        $this->registerLaravelChannel()
            ->registerNavigation()
            ->registerPermissions()
            ->bootCommands()
            ->registerBundledSources()
            ->registerPublishables();
    }

    /**
     * Lets `via(['notifications'])` route a Laravel notification into the
     * persisted store — the interop path chosen instead of building on
     * Laravel's own table.
     */
    protected function registerLaravelChannel(): self
    {
        LaravelNotification::extend('notifications', fn () => app(LaravelChannelAdapter::class));

        return $this;
    }

    /**
     * Bundled sources attach only when their sibling addon is installed. The
     * dependency stays one-directional: notifications knows about LeadHub,
     * never the other way round.
     */
    protected function registerBundledSources(): self
    {
        if (config('notifications.sources.leadhub', true) && class_exists(\Goldnead\Leadhub\Models\Contact::class)) {
            app('notifications')->registerSource('leadhub', LeadHubSource::class);
            LeadHubSource::registerTypes(app('notifications'));
        }

        return $this;
    }

    protected function bootCommands(): self
    {
        if ($this->app->runningInConsole()) {
            $this->commands($this->commands);
        }

        return $this;
    }

    protected function registerNavigation(): self
    {
        if (! config('notifications.cp.enabled', true)) {
            return $this;
        }

        Nav::extend(function ($nav): void {
            $nav->create(__('notifications::cp.nav'))
                ->section('Tools')
                ->icon('<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0" /></svg>')
                ->route('notifications.index')
                ->can('view notifications');
        });

        return $this;
    }

    protected function registerPermissions(): self
    {
        Permission::extend(function (): void {
            Permission::group('notifications', __('notifications::cp.nav'), function (): void {
                Permission::register('view notifications')
                    ->label(__('notifications::cp.permission_view'))
                    ->children([
                        Permission::make('manage notification digests')
                            ->label(__('notifications::cp.permission_digests')),
                    ]);
            });
        });

        return $this;
    }

    protected function registerPublishables(): self
    {
        $this->publishes([
            __DIR__.'/../config/notifications.php' => config_path('notifications.php'),
        ], 'notifications-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'notifications-migrations');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/notifications'),
        ], 'notifications-views');

        $this->publishes([
            __DIR__.'/../resources/lang' => lang_path('vendor/notifications'),
        ], 'notifications-translations');

        return $this;
    }
}
