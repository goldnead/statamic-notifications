<?php

namespace Goldnead\Notifications\Channels;

use Goldnead\IdentityContracts\Identity;
use Goldnead\Notifications\Contracts\Channel;
use Goldnead\Notifications\Mail\NotificationMail;
use Goldnead\Notifications\Models\NotificationItem;
use Goldnead\Notifications\Types\TypeRegistry;
use Illuminate\Support\Facades\Mail;

/**
 * Immediate one-per-notification e-mail. The subject and body come from the
 * type's renderer, so the addon never writes a sentence of its own.
 */
class MailChannel implements Channel
{
    public function __construct(protected TypeRegistry $types) {}

    public function send(NotificationItem $item, Identity $recipient): void
    {
        $address = $recipient->email ?? $item->email;

        if ($address === null || $address === '') {
            return;
        }

        $rendered = $this->types->get($item->type)->render($item);

        Mail::to($address)->send(new NotificationMail($item, $rendered));
    }
}
