<?php

namespace Goldnead\Notifications\Query\Scopes\Filters;

use Statamic\Query\Scopes\Filter;

class ReadState extends Filter
{
    protected static $handle = 'notification_read_state';

    protected $pinned = true;

    public static function title()
    {
        return __('notifications::cp.filter_read_state');
    }

    public function fieldItems()
    {
        return [
            'state' => [
                'type' => 'select',
                'placeholder' => __('notifications::cp.filter_read_state_placeholder'),
                'clearable' => true,
                'options' => $this->options(),
            ],
        ];
    }

    public function apply($query, $values)
    {
        match ($values['state'] ?? null) {
            'unread' => $query->whereNull('read_at'),
            'read' => $query->whereNotNull('read_at'),
            default => null,
        };
    }

    public function badge($values)
    {
        return __('notifications::cp.filter_read_state').': '
            .($this->options()[$values['state']] ?? $values['state']);
    }

    public function visibleTo($key)
    {
        return $key === 'notifications';
    }

    /** @return array<string, string> */
    protected function options(): array
    {
        return [
            'unread' => __('notifications::cp.unread'),
            'read' => __('notifications::cp.read'),
        ];
    }
}
