<?php

namespace Goldnead\Notifications;

use Goldnead\Leadhub\Models\Contact;
use Goldnead\Notifications\Channels\ChannelRegistry;
use Goldnead\Notifications\Channels\LaravelChannelAdapter;
use Goldnead\Notifications\Console\SendDigestsCommand;
use Goldnead\Notifications\Console\UniquenessIntegrityCommand;
use Goldnead\Notifications\Contracts\RecipientDirectory;
use Goldnead\Notifications\Digest\DigestBuilder;
use Goldnead\Notifications\Digest\PendingItemRecipientDirectory;
use Goldnead\Notifications\Digest\SourceRegistry;
use Goldnead\Notifications\Preferences\PreferenceResolver;
use Goldnead\Notifications\Query\Scopes\Filters\NotificationType;
use Goldnead\Notifications\Query\Scopes\Filters\ReadState;
use Goldnead\Notifications\Query\Scopes\Filters\Recipient;
use Goldnead\Notifications\Realtime\BroadcastAdapter;
use Goldnead\Notifications\Sources\LeadHubSource;
use Goldnead\Notifications\Support\AutomationRules;
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
        UniquenessIntegrityCommand::class,
    ];

    protected $scopes = [
        NotificationType::class,
        ReadState::class,
        Recipient::class,
    ];

    /**
     * Statamic 6 reads an addon's Vite configuration from this property alone.
     * `extra.statamic.vite` in composer.json is kept in sync with it for
     * readers and tooling, but it is not what the CP consults — a build that
     * only lives there is a build that never loads.
     *
     * The three values must byte-match `laravel()` in vite.config.js.
     *
     * The parent declares this `list<string>`, which describes the old
     * `$scripts` shape rather than the one `bootVite()` actually reads
     * (`AddonServiceProvider::registerVite()` destructures `input` and
     * `publicDirectory`). Restating the real shape here keeps static analysis
     * honest instead of carrying the upstream docblock's mistake in a baseline.
     *
     * @var array{input: list<string>, publicDirectory: string}
     */
    protected $vite = [
        'input' => [
            'resources/js/cp.js',
            'resources/css/cp.css',
        ],
        'publicDirectory' => 'resources/dist',
    ];

    /*
     * Still set, and still needed: `resources/views` holds the mail templates
     * (`notifications::mail.notification`, `notifications::mail.digest`), which
     * stay Blade. Only the two CP screens moved to Inertia.
     */
    protected $viewNamespace = 'notifications';

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
            $app->make(PreferenceResolver::class),
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

        $this->registerLaravelChannel()
            ->registerNavigation()
            ->registerPermissions()
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
        if (config('notifications.sources.leadhub', true) && class_exists(Contact::class)) {
            app('notifications')->registerSource('leadhub', LeadHubSource::class);
            LeadHubSource::registerTypes(app('notifications'));
        }

        return $this;
    }

    protected function registerNavigation(): self
    {
        if (! config('notifications.cp.enabled', true)) {
            return $this;
        }

        Nav::extend(function ($nav): void {
            $item = $nav->create(__('notifications::cp.nav'))
                ->section('Tools')
                ->icon('bell')
                ->route('notifications.index')
                ->can('view notifications');

            // Transactional mail rules are configured in the automations
            // addon, and somebody looking for them looks here first. So this
            // points at that screen when it exists — a signpost, never a second
            // place where an event turns into a mail. See AutomationRules.
            if (AutomationRules::available()) {
                $item->children([
                    $nav->item(__('notifications::cp.nav_mail_rules'))
                        ->route(AutomationRules::NAV_ROUTE),
                ]);
            }
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

    /**
     * Only the two tags the base class has no equivalent for.
     * `notifications-config` comes from AddonServiceProvider::bootConfig() and
     * `notifications-translations` from bootTranslations(); repeating them here
     * would be two implementations of the same tag drifting apart.
     */
    protected function registerPublishables(): self
    {
        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'notifications-migrations');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/notifications'),
        ], 'notifications-views');

        return $this;
    }
}
