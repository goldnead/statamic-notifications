<?php

namespace Goldnead\Notifications\Tests\Fixtures;

use Illuminate\Notifications\Notification;

/**
 * The interop shape a host uses to route an existing Laravel notification into
 * the persisted store.
 */
class LeadAssignedLaravelNotification extends Notification
{
    /** @return array<int, string> */
    public function via(mixed $notifiable): array
    {
        return ['notifications'];
    }

    /** @return array<string, mixed> */
    public function toNotifications(mixed $notifiable): array
    {
        return [
            'type' => 'crm.lead_assigned',
            'message' => 'Dir wurde ein Lead zugewiesen.',
            'link' => '/cp/leadhub/contacts/1',
        ];
    }
}
