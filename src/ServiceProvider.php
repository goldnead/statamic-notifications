<?php

namespace Goldnead\Notifications;

use Goldnead\Leadhub\Models\Contact;
use Goldnead\Notifications\Channels\ChannelRegistry;
use Goldnead\Notifications\Channels\LaravelChannelAdapter;
use Goldnead\Notifications\Console\SendDigestsCommand;
use Goldnead\Notifications\Console\UniquenessIntegrityCommand;
use Goldnead\Notifications\Contracts\RecipientDirectory;
use Goldnead\Notifications\Contracts\SenderIdentityResolver;
use Goldnead\Notifications\Digest\DigestBuilder;
use Goldnead\Notifications\Digest\PendingItemRecipientDirectory;
use Goldnead\Notifications\Digest\SourceRegistry;
use Goldnead\Notifications\Integrations\Insights\Digests;
use Goldnead\Notifications\Integrations\Insights\Read;
use Goldnead\Notifications\Integrations\Insights\ReadRate;
use Goldnead\Notifications\Integrations\Insights\Sent;
use Goldnead\Notifications\Preferences\PreferenceResolver;
use Goldnead\Notifications\Query\Scopes\Filters\NotificationType;
use Goldnead\Notifications\Query\Scopes\Filters\ReadState;
use Goldnead\Notifications\Query\Scopes\Filters\Recipient;
use Goldnead\Notifications\Realtime\BroadcastAdapter;
use Goldnead\Notifications\Sending\BrandMailer;
use Goldnead\Notifications\Sending\BrandSenderIdentity;
use Goldnead\Notifications\Sources\LeadHubSource;
use Goldnead\Notifications\Support\AutomationRules;
use Goldnead\Notifications\Types\TypeRegistry;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification as LaravelNotification;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Permission;
use Statamic\Providers\AddonServiceProvider;
use Throwable;

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

        // Who a notification goes out as, and over which transport. Bound to
        // an interface so a host that keeps sender identities somewhere other
        // than `brands.settings.mail` rebinds it instead of patching the
        // addon; the shipped implementation leaves a single-brand install
        // sending exactly as before.
        $this->app->singleton(SenderIdentityResolver::class, BrandSenderIdentity::class);
        $this->app->singleton(BrandMailer::class);

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
            ->registerPublishables()
            ->registerInsightsMetrics();
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
     * Die Handles, die dieses Addon beisteuert, und die Klassen dahinter.
     *
     * Beides, damit die Registry den Klassennamen ablegen kann, ohne ein Objekt
     * zu bauen, nur um zu erfahren, wie es heisst. Den Handle zweimal zu
     * schreiben ist der Preis dafuer und die guenstigere Haelfte des Tauschs:
     * eine Installation mit zwanzig Addons wuerde sonst bei jedem Aufruf jede
     * Kennzahl jedes Addons instanziieren, auch auf Seiten, die keine zeigen.
     *
     * Die Handles sind ab der Registrierung eingefroren — sie landen in
     * gespeicherten Ansichten und in URLs. Einen umzubenennen ist ein Bruch.
     *
     * @var array<class-string, string>
     */
    protected const INSIGHTS_METRICS = [
        Sent::class => 'notifications.sent',
        Read::class => 'notifications.read',
        ReadRate::class => 'notifications.read_rate',
        Digests::class => 'notifications.digests',
    ];

    /**
     * Die vier Betriebszahlen dem Analytics-Addon anbieten, falls es da ist.
     *
     * Aus einem `app->booted()`-Rueckruf und nicht direkt aus `bootAddon()`:
     * die Container-Bindungen des Geschwisters entstehen erst, wenn dessen
     * eigener Provider gebootet hat, und dieser hier kann vorher dran sein. Wer
     * frueher registriert, registriert ins Leere — ohne Fehler, mit einem
     * leeren Schirm als einzigem Hinweis, was die schlechteste Form dieses
     * Fehlschlags ist.
     *
     * **Hier wirft nichts, nie.** Ein fehlendes, halb installiertes oder gerade
     * aktualisiertes Analytics-Addon darf ein paar Kacheln kosten, niemals eine
     * Benachrichtigung. Die drei Absicherungen sind je eine reale Spielart von
     * „installiert, aber nicht ganz": die Klasse kann fehlen, der Container
     * kann die Verwaltung nicht bauen, und eine aeltere Fassung des
     * Geschwisters kann die Fassade ohne diese Methode haben.
     *
     * Die Kennzahl-Klassen nennen den fremden Vertrag in ihrem `extends`, was
     * genau wegen der ersten Absicherung sicher ist: PHP laedt eine Klasse,
     * wenn etwas sie anfasst, und nichts fasst diese an, solange die Fassade
     * fehlt. Daher `suggest` in der composer.json und nicht `require` — dies
     * ist ein Grundlagen-Addon, und ein Grundlagen-Addon schleppt kein
     * Auswertungspaket mit.
     */
    protected function registerInsightsMetrics(): self
    {
        $this->app->booted(function (): void {
            $facade = '\Goldnead\StatamicInsights\Facades\Insights';

            if (! class_exists($facade)) {
                return;
            }

            try {
                $manager = $facade::getFacadeRoot();

                // Am Objekt gefragt, nie an der Fassade: eine Fassade reicht
                // ueber `__callStatic` weiter und deklariert nichts von dem,
                // was sie weiterreicht — die Probe an der Fassade selbst ist
                // immer falsch.
                if (! is_object($manager) || ! method_exists($manager, 'registerMetric')) {
                    return;
                }

                foreach (self::INSIGHTS_METRICS as $class => $handle) {
                    $manager->registerMetric($class, $handle);
                }
            } catch (Throwable $e) {
                Log::warning('statamic-notifications: the insights metrics could not be registered.', [
                    'exception' => $e->getMessage(),
                ]);
            }
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
