<?php

namespace Goldnead\Notifications\Query\Scopes\Filters;

use Statamic\Query\Scopes\Filter;

/**
 * One field for whichever join key the notification happened to be written
 * with. Support is given "the user" — an id, an address, a contact uuid — and
 * should not have to know which column the producer filled in.
 */
class Recipient extends Filter
{
    protected static $handle = 'notification_recipient';

    public static function title()
    {
        return __('notifications::cp.filter_recipient');
    }

    public function fieldItems()
    {
        return [
            'recipient' => [
                'type' => 'text',
                'placeholder' => __('notifications::cp.filter_recipient_placeholder'),
            ],
        ];
    }

    public function apply($query, $values)
    {
        if (blank($values['recipient'] ?? null)) {
            return;
        }

        $recipient = (string) $values['recipient'];

        $query->where(function ($query) use ($recipient): void {
            $query->where('user_id', $recipient)
                ->orWhere('email', $recipient)
                ->orWhere('contact_uuid', $recipient);
        });
    }

    public function badge($values)
    {
        return __('notifications::cp.filter_recipient').': '.$values['recipient'];
    }

    public function visibleTo($key)
    {
        return $key === 'notifications';
    }
}
