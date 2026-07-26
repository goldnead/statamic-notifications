<?php

namespace Goldnead\Notifications\Tests\Fixtures;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MailOnlyLaravelNotification extends Notification
{
    /** @return array<int, string> */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)->line('Nur Mail.');
    }
}
