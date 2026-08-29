<?php

namespace Goldnead\Notifications\Integrations\Insights;

use Goldnead\Notifications\Types\TypeRegistry;
use Goldnead\StatamicInsights\Contracts\HasBreakdowns;
use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Unit;
use Throwable;

/**
 * Wie viele Benachrichtigungen herausgegangen sind.
 *
 * Auf `created_at` gefenstert, und das ist hier kein Kompromiss: die Zeile
 * entsteht, wenn zugestellt wird. Ein `sent_at` hat die Tabelle nicht, weil es
 * dasselbe Datum waere.
 *
 * Aufgeteilt nach `type`. Warum es keine Aufteilung nach Kanal gibt, steht in
 * {@see NotificationMetric}.
 */
class Sent extends NotificationMetric implements HasBreakdowns
{
    public function handle(): string
    {
        return 'notifications.sent';
    }

    public function label(): string
    {
        return __('notifications::insights.sent');
    }

    public function description(): ?string
    {
        return __('notifications::insights.sent_description');
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

    public function breakdowns(): array
    {
        return ['type' => __('notifications::insights.breakdown_type')];
    }

    public function breakdown(MetricQuery $query, string $dimension, int $limit = 20): array
    {
        if (! $this->available() || $dimension !== 'type') {
            return [];
        }

        $rows = $this->splitByColumn($this->untilNow($query), $query, 'type', 'count(*)', $limit);

        return array_map(fn (array $row) => [
            'key' => $row['key'],
            'label' => $row['key'] === null ? $this->missingLabel('type') : $this->typeLabel($row['key']),
            'value' => $row['value'],
        ], $rows);
    }

    /**
     * Wie die Art heisst, wenn sie jemand angemeldet hat.
     *
     * Ueber die {@see TypeRegistry}, denn dort und nur dort bekommt ein Handle
     * einen Namen. Eine Art, die nie registriert wurde, ist trotzdem zustellbar
     * — das ist eine ausdrueckliche Entscheidung des Addons, damit eine
     * vergessene Registrierung niemandem seine Benachrichtigung kostet — und
     * behaelt hier folgerichtig ihr Handle, statt aus der Aufteilung zu
     * verschwinden.
     *
     * Die Registry haengt an der Anwendung und kann von einem fremden Addon
     * befuellt sein, das beim Aufloesen wirft. Dann kostet das eine
     * Beschriftung, nie die ganze Kachel.
     */
    protected function typeLabel(string $type): string
    {
        try {
            $label = app(TypeRegistry::class)->get($type)->label;
        } catch (Throwable) {
            return $type;
        }

        return is_string($label) && $label !== '' ? $label : $type;
    }
}
