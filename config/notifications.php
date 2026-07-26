<?php

use Goldnead\Notifications\Channels\DigestChannel;
use Goldnead\Notifications\Channels\InAppChannel;
use Goldnead\Notifications\Channels\MailChannel;

return [

    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    */

    'enabled' => env('NOTIFICATIONS_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Channels
    |--------------------------------------------------------------------------
    |
    | Handle => implementation. A type's default channels and a recipient's
    | preferences are both expressed in these handles. Add your own with
    | Notifications::registerChannel().
    |
    */

    'channels' => [
        'in_app' => InAppChannel::class,
        'mail' => MailChannel::class,
        'digest' => DigestChannel::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Digest
    |--------------------------------------------------------------------------
    |
    | `default_frequency` applies to anyone who never chose one. Scheduling is
    | left to the host: register `notifications:send-digests --frequency=weekly`
    | in your own scheduler so the send window matches your audience, not ours.
    |
    */

    'digest' => [
        'default_frequency' => env('NOTIFICATIONS_DIGEST_FREQUENCY', 'weekly'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Realtime
    |--------------------------------------------------------------------------
    |
    | Off by default. When on, a content-free refresh signal is broadcast on
    | `<prefix>.<user_id>`; the client re-fetches through the normal endpoint.
    | Requires a working broadcaster (Reverb, Pusher).
    |
    */

    'realtime' => [
        'enabled' => env('NOTIFICATIONS_REALTIME', false),
        'channel_prefix' => 'users',
    ],

    /*
    |--------------------------------------------------------------------------
    | Listing
    |--------------------------------------------------------------------------
    */

    'list_limit' => 30,

    /*
    |--------------------------------------------------------------------------
    | Preference centre URL
    |--------------------------------------------------------------------------
    |
    | Printed in digest mails. The addon ships no preference UI of its own —
    | the host owns that page.
    |
    */

    'preferences_url' => env('NOTIFICATIONS_PREFERENCES_URL'),

];
