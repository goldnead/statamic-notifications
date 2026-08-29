<?php

namespace Goldnead\Notifications\Tests\Feature;

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\Notifications\Integrations\Insights\Digests;
use Goldnead\Notifications\Integrations\Insights\Read;
use Goldnead\Notifications\Integrations\Insights\ReadRate;
use Goldnead\Notifications\Integrations\Insights\Sent;
use Goldnead\Notifications\Models\NotificationDigestRun;
use Goldnead\Notifications\Models\NotificationItem;
use Goldnead\Notifications\Tests\TestCase;
use Goldnead\Notifications\Types\TypeRegistry;
use Goldnead\StatamicInsights\Contracts\Metric;
use Goldnead\StatamicInsights\Facades\Insights as InsightsStandIn;
use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Period;
use Goldnead\StatamicInsights\Support\TableMetric;
use Goldnead\StatamicInsights\Support\Unit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * Die vier Betriebszahlen, die dieses Addon dem Analytics-Addon anbietet.
 *
 * Der Kern dieser Datei ist ein Unterschied, den man auf einem Schirm sonst
 * nicht sieht: `Gelesen` fenstert auf `read_at` und `Leserate` auf
 * `created_at`. Die Vorlage unten ist so gebaut, dass die beiden Zahlen
 * auseinanderfallen — drei Lesebewegungen, aber nur zwei davon an etwas, das
 * in diesem Zeitraum verschickt wurde. Wer die Quote aus den beiden Kacheln
 * rechnet, kommt auf 60 statt 40 Prozent, und genau davor warnt die
 * Beschreibung der Kennzahl.
 *
 * Gegen einen Stellvertreter des Vertrags getestet und nicht gegen das echte
 * Paket, aus demselben Grund, aus dem das Geschwister ein `suggest` ist.
 *
 * Die Zeit ist eingefroren, weil die Eimer als konkrete Daten geprueft werden.
 */
class InsightsMetricsTest extends TestCase
{
    protected const HEUTE = '2026-08-20 12:00:00';

    /** Sammelt ein, was der ServiceProvider registriert. */
    protected object $insights;

    protected function setUp(): void
    {
        // Vor der Anwendung, alle drei. Die Vertraege muessen da sein, bevor
        // eine Kennzahl-Klasse geladen wird, und die Fassade, bevor der
        // Provider in seinem `booted()`-Rueckruf fragt, ob es sie gibt.
        //
        // Die Basisklasse liegt als eigene Datei daneben und traegt keine
        // Absicherung im Kopf: sie ist eine Byte-fuer-Byte-Kopie, und die
        // Absicherung sitzt deshalb hier. Siehe InsightsContractsMatchTest.
        require_once __DIR__.'/../Fakes/insights-contracts.php';

        if (! class_exists(TableMetric::class, false)) {
            require_once __DIR__.'/../Fakes/insights-table-metric.php';
        }

        require_once __DIR__.'/../Fakes/insights-facade.php';

        $this->insights = new class
        {
            /** @var array<string, string> */
            public array $registered = [];

            /**
             * Strenger als die echte Verwaltung, mit Absicht: die nimmt eine
             * Kennzahl auch ohne Handle an und ermittelt ihn, indem sie sie
             * baut. Das hier anzunehmen hiesse, dass der Provider den Handle
             * weglassen koennte und trotzdem richtig aussieht — und der Handle
             * ist die Haelfte, die in gespeicherten Ansichten und URLs landet.
             */
            public function registerMetric(string|Metric|\Closure $metric, ?string $handle = null): void
            {
                if (! is_string($metric) || $handle === null) {
                    throw new \InvalidArgumentException('This addon registers metrics lazily: a class name and a handle.');
                }

                $this->registered[$handle] = $metric;
            }
        };

        InsightsStandIn::$root = $this->insights;

        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::HEUTE));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        InsightsStandIn::$root = null;

        parent::tearDown();
    }

    // -- Die Vorlage --------------------------------------------------------

    /**
     * Sechs Benachrichtigungen und drei Digest-Laeufe.
     *
     * Klein genug, um sie im Kopf zu addieren, und jeder Fall, der eine Zahl
     * kippen kann, ist drin: eine alte Meldung, die erst in diesem Zeitraum
     * gelesen wurde, eine ungelesene, eine ohne Art, ein Digest-Lauf, der
     * vorgemerkt und nie verschickt wurde, und je eine Zeile ausserhalb des
     * Fensters.
     *
     * Im Fenster (11.–20.08.): fuenf verschickt, zwei davon inzwischen gelesen.
     * Gelesen wurde in diesem Fenster dreimal — die dritte Lesebewegung gilt
     * einer Meldung vom 1. Juli.
     */
    protected function fixture(): void
    {
        $this->item('comment.reply', '2026-08-15 09:00:00', '2026-08-15 10:00:00');
        $this->item('comment.reply', '2026-08-15 11:00:00', null);
        $this->item('lead.assigned', '2026-08-18 08:00:00', '2026-08-19 09:00:00');
        $this->item('lead.assigned', '2026-08-19 08:00:00', null);

        // Eine Art, die niemand gesetzt hat. `type` ist nicht nullable, also
        // ist das hier die Form, in der „ohne Art" wirklich vorkommt — und die
        // Aufteilung behandelt den leeren String wie ein `null`.
        $this->item('', '2026-08-19 12:00:00', null);

        // Vom 1. Juli, aber am 16. August gelesen. Die Zeile, an der `Gelesen`
        // und `Leserate` auseinanderfallen.
        $this->item('comment.reply', '2026-07-01 08:00:00', '2026-08-16 12:00:00');

        $this->digestRun('2026-08-17 10:00:00');

        // Vorgemerkt, nie verschickt. Ohne `sent_at` liegt der Lauf in keinem
        // Zeitraum, auch nicht im groessten.
        $this->digestRun(null, '2026-08-18 06:00:00');

        $this->digestRun('2026-07-02 10:00:00');
    }

    protected function item(string $type, string $createdAt, ?string $readAt): NotificationItem
    {
        return NotificationItem::create([
            'type' => $type,
            'user_id' => 'u-1',
            'message' => 'Etwas ist passiert.',
            'read_at' => $readAt,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    protected function digestRun(?string $sentAt, string $windowStart = '2026-08-17 00:00:00'): NotificationDigestRun
    {
        return NotificationDigestRun::create([
            'user_id' => 'u-'.uniqid(),
            'frequency' => 'daily',
            'window_start' => $windowStart,
            'window_end' => Carbon::parse($windowStart)->addDay(),
            'item_count' => 3,
            'sent_at' => $sentAt,
        ]);
    }

    /** Die zehn Tage, in denen die Vorlage lebt, nach Tagen gebucketet. */
    protected function frage(string $bucket = MetricQuery::BUCKET_DAY): MetricQuery
    {
        return new MetricQuery(
            Period::between(Carbon::parse('2026-08-11')->startOfDay(), Carbon::parse('2026-08-20')->endOfDay()),
            $bucket,
        );
    }

    /** Ein leeres Fenster: alles davor. */
    protected function stillesFenster(): MetricQuery
    {
        return new MetricQuery(
            Period::between(Carbon::parse('2026-08-06')->startOfDay(), Carbon::parse('2026-08-10')->endOfDay()),
        );
    }

    /**
     * @param  array<int, array{key: string|null, label: string, value: int|float}>  $rows
     * @return array<string, int|float>
     */
    protected function keyed(array $rows): array
    {
        $keyed = [];

        foreach ($rows as $row) {
            $keyed[$row['key'] ?? ''] = $row['value'];
        }

        return $keyed;
    }

    // -- Die vier Zahlen ----------------------------------------------------

    /**
     * Alle vier auf einmal, gegen von Hand gerechnete Summen.
     *
     * Und die Zeile, die alles erklaert: `Gelesen` ist drei, `Verschickt` ist
     * fuenf, die `Leserate` ist trotzdem vierzig und nicht sechzig Prozent. Die
     * drei Lesebewegungen enthalten eine an einer Meldung vom Juli; die Quote
     * fragt nur nach dem, was in diesem Zeitraum herausging.
     */
    #[Test]
    public function the_four_figures_match_what_the_tables_say(): void
    {
        $this->fixture();
        $frage = $this->frage();

        $this->assertSame(5, (new Sent)->value($frage), 'fuenf verschickt im Fenster, eine im Juli');
        $this->assertSame(3, (new Read)->value($frage), 'drei Lesebewegungen im Fenster, eine davon an der Juli-Meldung');

        // 2 von 5, nicht 3 von 5.
        $this->assertSame(40.0, (new ReadRate)->value($frage), 'round(2 / 5 * 100, 1)');

        $this->assertSame(1, (new Digests)->value($frage), 'ein verschickter Lauf; der vorgemerkte zaehlt nicht');
    }

    /**
     * Die Quote ist keine Division der beiden Kacheln neben ihr.
     *
     * Der Test, der die Wahl der Spalte traegt. Zaehler und Nenner fenstern
     * beide auf `created_at`; wuerde der Zaehler auf `read_at` fenstern, kaeme
     * hier 60 Prozent heraus — eine Quote ueber Zeilen, die der Nenner nie
     * gesehen hat, und in einem Fenster mit viel Aufraeumen und wenig Versand
     * ginge sie ueber hundert.
     */
    #[Test]
    public function the_rate_is_not_read_divided_by_sent(): void
    {
        $this->fixture();
        $frage = $this->frage();

        $gelesen = (new Read)->value($frage);
        $verschickt = (new Sent)->value($frage);

        $this->assertSame(60.0, round($gelesen / $verschickt * 100, 1), 'was die naive Division saegte');
        $this->assertSame(40.0, (new ReadRate)->value($frage), 'und was die Kohorte wirklich sagt');
    }

    /** Die Handles sind ein Versprechen. Sie landen in Ansichten und URLs. */
    #[Test]
    public function the_handles_units_and_group_are_the_ones_that_were_promised(): void
    {
        $erwartet = [
            [Sent::class, 'notifications.sent', Unit::COUNT],
            [Read::class, 'notifications.read', Unit::COUNT],
            [ReadRate::class, 'notifications.read_rate', Unit::PERCENT],
            [Digests::class, 'notifications.digests', Unit::COUNT],
        ];

        foreach ($erwartet as [$klasse, $handle, $unit]) {
            $metrik = new $klasse;

            $this->assertSame($handle, $metrik->handle());
            $this->assertSame($unit, $metrik->unit());
            $this->assertSame(__('notifications::insights.group'), $metrik->group());
            $this->assertNotSame('', $metrik->label());
            $this->assertNotEmpty($metrik->description());
            $this->assertSame([], $metrik->meta($this->frage()));
        }
    }

    /** Der Provider bietet genau diese vier an, faul und mit Handle. */
    #[Test]
    public function the_provider_offers_every_figure_to_the_sibling(): void
    {
        $this->assertSame([
            'notifications.sent' => Sent::class,
            'notifications.read' => Read::class,
            'notifications.read_rate' => ReadRate::class,
            'notifications.digests' => Digests::class,
        ], $this->insights->registered);
    }

    // -- Ueber die Zeit -----------------------------------------------------

    /** Jede der drei Reihen bucketet auf ihrer eigenen Spalte. */
    #[Test]
    public function each_series_buckets_on_its_own_column(): void
    {
        $this->fixture();
        $frage = $this->frage();

        $this->assertSame(
            ['2026-08-15' => 2, '2026-08-18' => 1, '2026-08-19' => 2],
            (new Sent)->series($frage),
            'nach `created_at`',
        );

        $this->assertSame(
            ['2026-08-15' => 1, '2026-08-16' => 1, '2026-08-19' => 1],
            (new Read)->series($frage),
            'nach `read_at` — der 16. traegt die Juli-Meldung',
        );

        $this->assertSame(
            ['2026-08-15' => 50.0, '2026-08-18' => 100.0, '2026-08-19' => 0.0],
            (new ReadRate)->series($frage),
            'je Tag die Kohorte dieses Tages',
        );

        $this->assertSame(['2026-08-17' => 1], (new Digests)->series($frage));
    }

    /**
     * Ein Lauf ohne `sent_at` liegt in keinem Zeitraum, auch nicht im groessten.
     *
     * Beim Preset `all` sind beide Grenzen `null`, die Fenster-Bedingungen
     * fallen also ersatzlos weg. Ohne das `whereNotNull` der Basisklasse
     * meldete die Kachel dort jeden je vorgemerkten Lauf als verschickt — und
     * ausgerechnet im weitesten Bereich, wo niemand die Zahl nachrechnet.
     */
    #[Test]
    public function a_digest_that_was_never_sent_is_in_no_period_not_even_all_time(): void
    {
        $this->fixture();

        $alleZeit = new MetricQuery(Period::fromPreset('all'), MetricQuery::BUCKET_MONTH);

        $this->assertSame(3, NotificationDigestRun::query()->count(), 'die Tabelle haelt drei Laeufe');
        $this->assertSame(2, (new Digests)->value($alleZeit), 'zwei davon sind wirklich hinausgegangen');
        $this->assertSame(['2026-07' => 1, '2026-08' => 1], (new Digests)->series($alleZeit));
    }

    /**
     * Eine Zusammenfassung mit einem Versanddatum in der Zukunft ist keine.
     *
     * Das Gegenstueck zum Test darueber: „gesamter Zeitraum" hat auch keine
     * OBERE Grenze. Bei dieser Tabelle ist das kein hypothetischer Fall — sie
     * traegt mit `window_start` und `window_end` echte Zukunft, weil ein Lauf
     * fuer ein Fenster vorgemerkt wird, bevor es zu Ende ist. Gefenstert wird
     * deshalb auf `sent_at`, und geklammert wird zusaetzlich.
     */
    #[Test]
    public function a_row_dated_in_the_future_is_not_history(): void
    {
        $this->fixture();

        $this->digestRun('2027-01-04 09:00:00', '2027-01-03 00:00:00');
        $this->item('comment.reply', '2027-01-04 09:00:00', null);

        $alleZeit = new MetricQuery(Period::fromPreset('all'), MetricQuery::BUCKET_MONTH);

        $this->assertSame(2, (new Digests)->value($alleZeit), 'unveraendert: der geplante Versand hat nicht stattgefunden');
        $this->assertArrayNotHasKey('2027-01', (new Digests)->series($alleZeit));

        $this->assertSame(6, (new Sent)->value($alleZeit), 'die fuenf im August plus die vom Juli');
        $this->assertArrayNotHasKey('2027-01', (new Sent)->series($alleZeit));
    }

    /**
     * Die Zeitzone der Anwendung verschiebt die Fenstergrenze nicht.
     *
     * Insights baut seinen Zeitraum aus `Carbon::now()`, also aus der Zeit der
     * Anwendung. Dieses Addon schreibt `created_at` und `read_at` mit `now()`
     * durch Eloquent, ebenfalls in Anwendungszeit — beide Seiten sind naiv
     * lokal. Ein Addon, das UTC schriebe, waere auf einer Installation in
     * Chicago um fuenf Stunden versetzt, und der Fehler zeigte sich nur an den
     * Raendern: eine Meldung um 23:30 fiele aus dem Tag heraus.
     */
    #[Test]
    public function the_window_holds_under_a_non_utc_application_timezone(): void
    {
        $vorher = date_default_timezone_get();

        config()->set('app.timezone', 'America/Chicago');
        date_default_timezone_set('America/Chicago');

        try {
            Carbon::setTestNow(Carbon::parse('2026-08-20 23:30:00'));

            NotificationItem::create([
                'type' => 'comment.reply',
                'user_id' => 'u-spaet',
                'message' => 'Kurz vor Mitternacht.',
                'read_at' => now(),
            ]);

            $frage = new MetricQuery(Period::fromPreset('7d'), MetricQuery::BUCKET_DAY);

            $this->assertSame(1, (new Sent)->value($frage), 'eine Meldung um 23:30 gehoert in den heutigen Tag');
            $this->assertSame(['2026-08-20' => 1], (new Sent)->series($frage));
            $this->assertSame(['2026-08-20' => 1], (new Read)->series($frage));
            $this->assertSame(100.0, (new ReadRate)->value($frage));
        } finally {
            date_default_timezone_set($vorher);
            config()->set('app.timezone', $vorher);
        }
    }

    // -- Nichts zu messen ---------------------------------------------------

    /**
     * Keine Tabelle, keine Antwort — und keine Null.
     *
     * Fuer `Digests` ist das kein hypothetischer Fall: die Tabelle kam mit
     * einer eigenen Migration, und eine Installation, die davor stehen
     * geblieben ist, hat sie nicht.
     */
    #[Test]
    public function a_metric_cannot_answer_without_its_table(): void
    {
        $this->assertTrue((new Sent)->available());
        $this->assertTrue((new Digests)->available());

        config()->set('database.connections.ohne_meldungen', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $vorher = DB::getDefaultConnection();
        DB::purge('ohne_meldungen');
        DB::setDefaultConnection('ohne_meldungen');

        try {
            foreach ([Sent::class, Read::class, ReadRate::class, Digests::class] as $klasse) {
                $metrik = new $klasse;

                $this->assertFalse($metrik->available(), $klasse.' antwortete ohne seine Tabelle.');
                $this->assertNull($metrik->value($this->frage()), $klasse.' lieferte einen Wert ohne seine Tabelle.');
                $this->assertSame([], $metrik->series($this->frage()));
            }
        } finally {
            DB::setDefaultConnection($vorher);
        }
    }

    /**
     * Eine Quote ohne Nenner ist `null`, nie null Prozent.
     *
     * „0 %" waere eine Aussage ueber Benachrichtigungen, die es nicht gab, und
     * stuende neben einer Versandzahl von null, die ihr widerspricht.
     */
    #[Test]
    public function a_rate_without_a_denominator_has_no_answer(): void
    {
        $this->fixture();

        $this->assertNull((new ReadRate)->value($this->stillesFenster()));
        $this->assertSame(0, (new Sent)->value($this->stillesFenster()), 'gezaehlt wird trotzdem, und zwar null');
        $this->assertSame(0, (new Read)->value($this->stillesFenster()));
    }

    // -- Die eine Aufteilung ------------------------------------------------

    /**
     * Nach Art, mit dem Namen aus der Registry — und ohne Kanal.
     *
     * Eine Art, die nie registriert wurde, ist trotzdem zustellbar (eine
     * ausdrueckliche Entscheidung des Addons) und behaelt hier ihr Handle,
     * statt aus der Aufteilung zu verschwinden. Eine Meldung ganz ohne Art ist
     * eine Zeile, keine Auslassung.
     *
     * Nach Kanal gibt es nichts aufzuteilen: `notification_items` traegt keinen.
     */
    #[Test]
    public function the_only_split_is_by_type_and_it_carries_registered_names(): void
    {
        app(TypeRegistry::class)->register('comment.reply', fn ($type) => $type->label('Antwort auf deinen Kommentar'));

        $this->fixture();
        $frage = $this->frage();

        $nachArt = $this->keyed((new Sent)->breakdown($frage, 'type'));
        ksort($nachArt);

        $this->assertSame([
            '' => 1,
            'comment.reply' => 2,
            'lead.assigned' => 2,
        ], $nachArt);

        $zeilen = (new Sent)->breakdown($frage, 'type');
        $beschriftungen = [];

        foreach ($zeilen as $zeile) {
            $beschriftungen[$zeile['key'] ?? ''] = $zeile['label'];
        }

        $this->assertSame('Antwort auf deinen Kommentar', $beschriftungen['comment.reply'], 'aus der Registry');
        $this->assertSame('lead.assigned', $beschriftungen['lead.assigned'], 'nicht registriert, also das Handle');
        $this->assertSame(__('notifications::insights.no_type'), $beschriftungen[''], 'und ohne Art eine eigene Zeile');

        $this->assertSame(5, array_sum(array_column($zeilen, 'value')), 'die Aufteilung addiert sich zur Versandzahl');
        $this->assertSame(['type'], array_keys((new Sent)->breakdowns()));

        // Einen Kanal traegt die Tabelle nicht. Siehe NotificationMetric.
        $this->assertSame([], (new Sent)->breakdown($frage, 'channel'));
    }

    // -- Eine Marke sieht ihre eigenen Zahlen -------------------------------

    /**
     * Im Mehrmarkenbetrieb zaehlt eine Kachel nur die Marke, die gerade gilt.
     *
     * `TableMetric` liest ueber den Query-Builder, an Eloquent und damit an
     * `BrandScope` vorbei. Bei Benachrichtigungen ist das mehr als ein
     * Schoenheitsfehler: die Zeilen tragen Namen und Adressen von Menschen.
     */
    #[Test]
    public function a_figure_counts_only_the_brand_that_is_current(): void
    {
        $this->enableMultiBrand();

        $a = $this->makeBrand('marke-a');
        $b = $this->makeBrand('marke-b');

        BrandContext::runFor($a->id, fn () => $this->item('comment.reply', '2026-08-14 10:00:00', '2026-08-14 11:00:00'));

        BrandContext::runFor($b->id, function () {
            $this->item('comment.reply', '2026-08-14 10:00:00', null);
            $this->item('comment.reply', '2026-08-14 11:00:00', null);
        });

        BrandContext::setCurrent($a->id);
        $this->assertSame(1, (new Sent)->value($this->frage()));
        $this->assertSame(1, (new Read)->value($this->frage()));
        $this->assertSame(100.0, (new ReadRate)->value($this->frage()));

        BrandContext::setCurrent($b->id);
        $this->assertSame(2, (new Sent)->value($this->frage()));
        $this->assertSame(0, (new Read)->value($this->frage()));
        $this->assertSame(0.0, (new ReadRate)->value($this->frage()), 'nichts gelesen ist nicht dasselbe wie nichts verschickt');
    }

    /**
     * Auch die Zusammenfassungen liegen in ihrer eigenen Marke.
     *
     * {@see Digests} rechnet auf `notification_digest_runs` und nicht auf
     * `notification_items`. Verengt wird gegen `table()`, also traegt die
     * zweite Tabelle dieselbe Bedingung wie die erste — was frueher an einer
     * eigenen `inPeriod()`-Ueberschreibung hing und jetzt an
     * {@see NotificationMetric::brandColumn()}.
     */
    #[Test]
    public function the_digests_count_only_the_brand_that_is_current(): void
    {
        $this->enableMultiBrand();

        $a = $this->makeBrand('marke-a');
        $b = $this->makeBrand('marke-b');

        BrandContext::runFor($a->id, fn () => $this->digestRun('2026-08-14 10:00:00'));

        BrandContext::runFor($b->id, function () {
            $this->digestRun('2026-08-14 11:00:00');
            $this->digestRun('2026-08-15 11:00:00');
        });

        BrandContext::setCurrent($a->id);
        $this->assertSame(1, (new Digests)->value($this->frage()));

        BrandContext::setCurrent($b->id);
        $this->assertSame(2, (new Digests)->value($this->frage()));
    }

    /**
     * Ohne aufgeloeste Marke wird die Kachel zu einer Null, nicht zu einer
     * Luecke.
     *
     * `available()` beantwortet, ob es die Sache gibt — steht die Tabelle, ist
     * das Geschwister da. Eine Marke, die niemand gewaehlt hat, ist nichts
     * davon. Verweigert werden weiterhin die Zeilen (`fail closed`), damit
     * nichts ueber Marken hinweg summiert wird; die Kachel selbst bleibt
     * stehen, denn eine Null kann ein Leser verstehen und eine verschwundene
     * Kachel nicht einmal bemerken.
     */
    #[Test]
    public function an_unresolved_brand_reads_nought_and_stays_on_the_screen(): void
    {
        $this->fixture();

        $this->enableMultiBrand();
        BrandContext::setCurrent(null);

        foreach ([new Sent, new Read, new ReadRate, new Digests] as $kennzahl) {
            $this->assertTrue(
                $kennzahl->available(),
                $kennzahl->handle().' ist von der Marke abhaengig geworden, statt von seiner Tabelle',
            );
        }

        $this->assertSame(0, (new Sent)->value($this->frage()));
        $this->assertSame(0, (new Read)->value($this->frage()));
        $this->assertSame(0, (new Digests)->value($this->frage()));
        $this->assertSame([], (new Sent)->series($this->frage()));

        // Ohne verschickte Zeile hat die Quote weiterhin keine Antwort. Das ist
        // eine Aussage ueber den Nenner, keine ueber die Marke.
        $this->assertNull((new ReadRate)->value($this->frage()));

        // Wo die Installation die andere Antwort vorzieht, liest die Kachel
        // ueber die Marken hinweg — wie `BrandScope` mit `fail_mode: open`.
        config()->set('brand-context.fail_mode', 'open');
        app('brand-context')->forget();
        BrandContext::setCurrent(null);

        $this->assertSame(5, (new Sent)->value($this->frage()));
    }
}
