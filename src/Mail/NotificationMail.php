<?php

namespace Goldnead\Notifications\Mail;

use Goldnead\Notifications\Models\NotificationItem;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class NotificationMail extends Mailable
{
    /**
     * @param  array{message: string|null, link: string|null, title: string|null}  $rendered
     */
    public function __construct(
        public NotificationItem $item,
        public array $rendered,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->rendered['title'] ?? $this->item->type,
        );
    }

    public function content(): Content
    {
        return new Content(
            // Overridable per host: `resources/views/vendor/notifications/mail/notification.blade.php`
            view: 'notifications::mail.notification',
            with: [
                'item' => $this->item,
                'title' => $this->rendered['title'] ?? null,
                'message' => $this->rendered['message'] ?? null,
                'link' => $this->rendered['link'] ?? null,
            ],
        );
    }
}
