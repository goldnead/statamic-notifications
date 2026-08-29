<?php

namespace Goldnead\Notifications\Integrations\Insights;

use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Unit;

/**
 * Wie viel in diesem Zeitraum gelesen wurde.
 *
 * **Auf `read_at` gefenstert, und damit eine andere Frage als {@see Sent}.**
 * Gezaehlt wird jede Lesebewegung, die in diesem Zeitraum passiert ist — auch
 * an einer Meldung von letzter Woche. Wer einen Rueckstau abarbeitet, sieht das
 * hier, und genau dafuer ist die Zahl da.
 *
 * **Deshalb ist `Gelesen ÷ Verschickt` nicht die Leserate.** Die steht in
 * {@see ReadRate} und rechnet anders herum: von dem, was in diesem Zeitraum
 * herausging, wie viel ist inzwischen gelesen. Beides nebeneinander zu zeigen
 * ist in Ordnung, solange die Beschriftung sagt, welche Frage welche ist — die
 * beiden Beschreibungen tun das. Was nicht in Ordnung waere: eine Quote aus
 * diesen beiden Kacheln zu bauen. Sie kann ueber hundert Prozent gehen, sobald
 * jemand seinen Posteingang aufraeumt.
 *
 * Eine ungelesene Meldung hat kein `read_at` und liegt damit in keinem
 * Zeitraum, auch nicht im groessten — das erledigt die Basisklasse.
 */
class Read extends NotificationMetric
{
    protected function timestamp(): string
    {
        return 'read_at';
    }

    public function handle(): string
    {
        return 'notifications.read';
    }

    public function label(): string
    {
        return __('notifications::insights.read');
    }

    public function description(): ?string
    {
        return __('notifications::insights.read_description');
    }

    public function unit(): string
    {
        return Unit::COUNT;
    }

    public function value(MetricQuery $query): int|float|null
    {
        if (! $this->available()) {
            return null;
        }

        return (int) $this->untilNow($query)->count();
    }

    public function series(MetricQuery $query): array
    {
        if (! $this->available()) {
            return [];
        }

        return array_map(
            fn ($measured) => (int) $measured,
            $this->bucketed($this->untilNow($query), $query, 'count(*)'),
        );
    }
}
