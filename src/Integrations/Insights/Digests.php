<?php

namespace Goldnead\Notifications\Integrations\Insights;

use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Unit;

/**
 * Wie viele Zusammenfassungen tatsaechlich verschickt wurden.
 *
 * Eigene Tabelle, eigener Zeitstempel: `notification_digest_runs.sent_at`. Und
 * `sent_at` und nicht `created_at`, weil die beiden hier auseinanderfallen —
 * die Zeile wird geschrieben, um den Versand fuer ein Fenster zu beanspruchen,
 * und `sent_at` bleibt `null`, wenn er nicht stattgefunden hat. Eine Zeile ohne
 * `sent_at` ist ein beanspruchter, aber nicht ausgefuehrter Lauf, und die
 * Basisklasse laesst sie damit aus jedem Zeitraum heraus, auch aus dem
 * groessten.
 *
 * Die Kennzahl faellt weg, wenn die Tabelle fehlt — eine Installation, die vor
 * 1.0.3 stehen geblieben ist, hat sie moeglicherweise nicht. Kein Nullwert:
 * „nichts zu messen" und „nichts gemessen" sind verschiedene Aussagen.
 */
class Digests extends NotificationMetric
{
    protected function table(): string
    {
        return 'notification_digest_runs';
    }

    protected function timestamp(): string
    {
        return 'sent_at';
    }

    public function handle(): string
    {
        return 'notifications.digests';
    }

    public function label(): string
    {
        return __('notifications::insights.digests');
    }

    public function description(): ?string
    {
        return __('notifications::insights.digests_description');
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
