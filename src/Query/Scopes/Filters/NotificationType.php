<?php

namespace Goldnead\Notifications\Query\Scopes\Filters;

use Goldnead\Notifications\Models\NotificationItem;
use Statamic\Query\Scopes\Filter;

/**
 * The options are the types actually present in the table rather than the ones
 * registered in the TypeRegistry: a type can be delivered without ever being
 * registered, and a support person filtering the inspector wants the list of
 * what happened, not the list of what was declared.
 *
 * The query runs under the brand scope, so brand A never sees brand B's type
 * names in the dropdown.
 */
class NotificationType extends Filter
{
    protected static $handle = 'notification_type';

    protected $pinned = true;

    public static function title()
    {
        return __('notifications::cp.filter_type');
    }

    public function fieldItems()
    {
        return [
            'type' => [
                'type' => 'select',
                'placeholder' => __('notifications::cp.filter_type_placeholder'),
                'clearable' => true,
                'options' => $this->options(),
            ],
        ];
    }

    public function apply($query, $values)
    {
        if (blank($values['type'] ?? null)) {
            return;
        }

        $query->where('type', $values['type']);
    }

    public function badge($values)
    {
        return __('notifications::cp.filter_type').': '.$values['type'];
    }

    public function visibleTo($key)
    {
        return $key === 'notifications';
    }

    /** @return array<string, string> */
    protected function options(): array
    {
        return NotificationItem::query()
            ->select('type')
            ->distinct()
            ->orderBy('type')
            ->pluck('type')
            ->mapWithKeys(fn ($type) => [$type => $type])
            ->all();
    }
}
