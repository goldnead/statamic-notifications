<?php

namespace Goldnead\Notifications\Integrations\Insights;

use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Unit;

/**
 * Von dem, was in diesem Zeitraum herausging: wie viel ist gelesen worden.
 *
 * **Zaehler und Nenner fenstern auf derselben Spalte**, naemlich auf
 * `created_at`. Das ist der ganze Punkt dieser Kennzahl und der Grund, warum
 * sie nicht `Gelesen ÷ Verschickt` aus den beiden Kacheln nebenan ist:
 * {@see Read} fenstert auf `read_at` und zaehlt damit auch Lesebewegungen an
 * aelteren Meldungen. Eine Quote aus zwei verschieden gefensterten Zahlen
 * zaehlt im Nenner Zeilen, die der Zaehler nie sehen kann, und steigt ueber
 * hundert Prozent, sobald jemand seinen Posteingang aufraeumt.
 *
 * Hier ist es eine Kohorte: was am 15. verschickt wurde, und wie viel davon
 * inzwischen gelesen ist — egal wann. Das ist stabil (der Wert eines
 * vergangenen Tages kann nur noch steigen), es kann nie ueber hundert Prozent
 * gehen, und es beantwortet die Frage, die jemand wirklich stellt: kommt an,
 * was wir schicken.
 *
 * Der Preis ist benannt: am rechten Rand des Zeitraums ist die Quote
 * naturgemaess niedriger, weil das von heute Morgen noch niemand gelesen hat.
 * Das gilt fuer jede Kohorten-Quote und ist der Grund, warum daneben die reine
 * Lesezahl steht.
 *
 * **Null ist nicht null Prozent.** Wurde nichts verschickt, gibt es keine
 * Antwort. „0 %" waere eine Aussage ueber Benachrichtigungen, die es nicht gab.
 */
class ReadRate extends NotificationMetric
{
    public function handle(): string
    {
        return 'notifications.read_rate';
    }

    public function label(): string
    {
        return __('notifications::insights.read_rate');
    }

    public function description(): ?string
    {
        return __('notifications::insights.read_rate_description');
    }

    public function unit(): string
    {
        return Unit::PERCENT;
    }

    public function value(MetricQuery $query): int|float|null
    {
        if (! $this->available()) {
            return null;
        }

        $row = $this->untilNow($query)
            ->selectRaw($this->cohortCounts())
            ->first();

        return $this->rate((int) ($row->sent ?? 0), (int) ($row->seen ?? 0));
    }

    /**
     * Eine Quote je Eimer, ueber die Kohorte dieses Eimers.
     *
     * Ein Eimer, der ueberhaupt auftaucht, hat mindestens eine verschickte
     * Zeile und damit immer einen Nenner. `null` steht hier trotzdem, statt
     * still auf Null zu fallen: die Regel gilt fuer jede Quote in dieser
     * Familie, und eine Ausnahme, die heute nie greift, ist eine Ausnahme, die
     * beim naechsten `where` greift.
     */
    public function series(MetricQuery $query): array
    {
        if (! $this->available()) {
            return [];
        }

        $rows = $this->untilNow($query)
            ->selectRaw($this->bucketExpression($query).' as bucket, '.$this->cohortCounts())
            ->groupBy('bucket')
            ->get();

        $buckets = [];

        foreach ($rows as $row) {
            $buckets[(string) $row->bucket] = $this->rate((int) $row->sent, (int) $row->seen);
        }

        ksort($buckets);

        return $buckets;
    }

    /**
     * Beide Haelften in einer Abfrage — und aus derselben Menge Zeilen, was
     * hier die eigentliche Zusicherung ist.
     */
    protected function cohortCounts(): string
    {
        return 'count(*) as sent, sum(case when read_at is not null then 1 else 0 end) as seen';
    }

    /** Eine Nachkommastelle. Mehr behauptet eine Genauigkeit, die zehn Meldungen nicht tragen. */
    protected function rate(int $sent, int $seen): ?float
    {
        return $sent > 0 ? round($seen / $sent * 100, 1) : null;
    }
}
