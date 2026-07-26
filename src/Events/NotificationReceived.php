<?php

namespace Goldnead\Notifications\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A refresh signal, not a payload. The client re-fetches through the normal,
 * authorised endpoint — so a socket subscriber can never see more than the API
 * would have given them.
 */
class NotificationReceived implements ShouldBroadcast
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public string $userId,
        public ?string $type = null,
    ) {}

    public function broadcastOn(): Channel
    {
        $prefix = (string) config('notifications.realtime.channel_prefix', 'users');

        return new PrivateChannel($prefix.'.'.$this->userId);
    }

    public function broadcastAs(): string
    {
        return 'NotificationReceived';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['reason' => 'refresh', 'type' => $this->type];
    }
}
