<?php

namespace Goldnead\Notifications\Integrations\Insights;

use Goldnead\StatamicInsights\Support\TableMetric;

/**
 * Was jede Benachrichtigungs-Zahl gemeinsam hat.
 *
 * Der Vertrag liegt drueben in `statamic-insights`: dieses Addon kennt seine
 * Tabellen, das Analytics-Addon kennt Zeitraum, Vergleich, Diagramm und
 * Bildschirm. Deshalb steht das Geschwister in `suggest` und nicht in
 * `require`, und deshalb wird keine Datei in diesem Ordner geladen, solange es
 * nicht installiert ist.
 *
 * **Gemessen wird Betrieb**: ging es raus, kam es an, wurde es gelesen. Die
 * Vorgabe fuer den Zeitstempel ist `created_at`, und anders als bei einer
 * Zahlung ist das hier auch der fachliche Zeitpunkt: eine Benachrichtigung
 * entsteht in dem Moment, in dem sie zugestellt wird — die Tabelle traegt kein
 * `sent_at`, weil das Anlegen der Zeile der Versand ist. Die Ausnahmen sagen es
 * selbst: {@see Read} fenstert auf `read_at`, {@see Digests} auf `sent_at`
 * seiner eigenen Tabelle.
 *
 * **Einen Kanal gibt es hier nicht.** `notification_items` traegt keinen —
 * `channel` steht auf `notification_preferences` und beantwortet „wo will diese
 * Person das haben", nicht „wo ging das hin". Eine Aufteilung nach Kanal waere
 * also erfunden, und eine erfundene Aufteilung ist schlimmer als keine: sie
 * sieht aus wie eine Messung. Aufgeteilt wird deshalb nur nach `type`.
 *
 * **Alle vier Zahlen sind auf jetzt geklammert** und fragen deshalb ueber
 * {@see TableMetric::untilNow()} statt ueber `inPeriod()`. Beim Preset
 * „gesamter Zeitraum" hat das Fenster keine obere Grenze, und was dann in einer
 * Zeitspalte in der Zukunft steht, wird als Geschehenes gemeldet. Verschickt,
 * gelesen und tatsaechlich versandte Zusammenfassungen beantworten alle drei,
 * *was passiert ist*.
 *
 * Bei den Zusammenfassungen ist das mehr als Vorsicht: `notification_digest_runs`
 * traegt mit `window_start` und `window_end` echte Zukunft — ein Lauf wird fuer
 * ein Fenster vorgemerkt, bevor es zu Ende ist. Deshalb fenstert {@see Digests}
 * auf `sent_at` und nicht auf dem Fenster selbst, und deshalb steht die Klammer
 * zusaetzlich. Eine Kennzahl „was steht als naechstes an" wuerde umgekehrt
 * **nicht** geklammert; die gibt es hier bewusst nicht.
 */
abstract class NotificationMetric extends TableMetric
{
    protected function table(): string
    {
        return 'notification_items';
    }

    protected function timestamp(): string
    {
        return 'created_at';
    }

    public function group(): string
    {
        return __('notifications::insights.group');
    }

    /**
     * Die Spalte, an der diese Tabellen ihre Marke tragen.
     *
     * Mehr braucht es nicht: {@see TableMetric::inPeriod()} verengt damit
     * Kachel, Verlauf und jede Aufteilung zugleich, nach genau den Regeln, nach
     * denen `BrandScope` jedes Modell dieses Addons verengt — und zwar auch fuer
     * {@see Digests}, das auf einer eigenen Tabelle rechnet, denn verengt wird
     * gegen {@see TableMetric::table()} und nicht gegen einen festen Namen.
     *
     * Bei Benachrichtigungen ist das mehr als ein Schoenheitsfehler: die Zeilen
     * tragen Namen und Adressen von Menschen, und eine Zahl, die im Zweifel
     * ueber alle Marken summiert, ist der erste Schritt zu einer Liste, die es
     * auch tut. Ist keine Marke aufgeloest, liefert die Kachel deshalb null
     * Zeilen — aber sie bleibt auf dem Schirm, denn eine Null ist lesbar und
     * eine verschwundene Kachel nicht.
     *
     * Im Einmarkenbetrieb filtert das nichts, wie drueben auch.
     */
    protected function brandColumn(): ?string
    {
        return 'brand_id';
    }

    protected function missingLabel(string $dimension): string
    {
        return __('notifications::insights.no_'.$dimension);
    }
}
