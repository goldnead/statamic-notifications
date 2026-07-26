<?php

namespace Goldnead\Notifications\Mail;

use Goldnead\IdentityContracts\Identity;
use Goldnead\Notifications\Facades\Notifications;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class DigestMail extends Mailable
{
    public function __construct(
        public Identity $recipient,
        public array $collected,
        public string $frequency,
    ) {}

    public function envelope(): Envelope
    {
        $key = 'notifications::mail.digest_subject_'.$this->frequency;

        return new Envelope(subject: __($key));
    }

    public function content(): Content
    {
        // Items are rendered through their registered types, so the wording
        // stays with whoever owns the domain rather than with this package.
        $rendered = $this->collected['items']->map(fn ($item) => [
            'item' => $item,
            ...Notifications::render($item),
        ]);

        return new Content(
            view: 'notifications::mail.digest',
            with: [
                'recipient' => $this->recipient,
                'items' => $rendered,
                'extras' => $this->collected['extras'],
                'window' => $this->collected['window'],
                'frequency' => $this->frequency,
                'preferencesUrl' => config('notifications.preferences_url'),
            ],
        );
    }
}
