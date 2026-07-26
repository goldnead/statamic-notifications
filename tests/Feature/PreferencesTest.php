<?php

use Goldnead\IdentityContracts\Identity;
use Goldnead\Notifications\Facades\Notifications;
use Goldnead\Notifications\Models\NotificationItem;
use Goldnead\Notifications\Preferences\PreferenceResolver;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    $this->preferences = app(PreferenceResolver::class);

    Notifications::registerType('community.mention', function ($type): void {
        $type->label('Erwähnung')->defaultChannels(['in_app', 'mail']);
    });

    Notifications::registerType('account.security', function ($type): void {
        $type->label('Sicherheit')->defaultChannels(['mail'])->required();
    });
});

it('falls back to the type default when nothing is stored', function (): void {
    $recipient = Identity::user(1);

    expect($this->preferences->allows($recipient, 'community.mention', 'mail'))->toBeTrue()
        ->and($this->preferences->allows($recipient, 'community.mention', 'digest'))->toBeFalse();
});

it('lets a stored preference override the default', function (): void {
    $recipient = Identity::user(1);

    $this->preferences->set($recipient, 'community.mention', 'mail', false);

    expect($this->preferences->allows($recipient, 'community.mention', 'mail'))->toBeFalse();
});

it('keeps one recipient preference from governing another', function (): void {
    $this->preferences->set(Identity::user(1), 'community.mention', 'mail', false);

    expect($this->preferences->allows(Identity::user(2), 'community.mention', 'mail'))->toBeTrue();
});

it('ignores preferences for a required type', function (): void {
    $recipient = Identity::user(1);

    $this->preferences->set($recipient, 'account.security', 'mail', false);

    expect($this->preferences->allows($recipient, 'account.security', 'mail'))->toBeTrue();
});

it('does not send mail when the recipient switched that channel off', function (): void {
    Mail::fake();
    $recipient = Identity::user(1, 'a@example.com');

    $this->preferences->set($recipient, 'community.mention', 'mail', false);

    Notifications::notify($recipient, 'community.mention', ['message' => 'hi']);

    Mail::assertNothingSent();

    // …but the record still exists. Preferences govern how someone is reached,
    // not whether the fact happened.
    expect(NotificationItem::count())->toBe(1);
});

it('sends mail when the channel is allowed', function (): void {
    Mail::fake();

    Notifications::notify(Identity::user(1, 'a@example.com'), 'community.mention', ['message' => 'hi']);

    Mail::assertSent(\Goldnead\Notifications\Mail\NotificationMail::class);
});

it('reports a digest frequency, defaulting to the configured one', function (): void {
    $recipient = Identity::user(1);

    expect($this->preferences->digestFrequency($recipient))->toBe('weekly');

    $this->preferences->set($recipient, 'community.mention', 'digest', true, 'daily');

    expect($this->preferences->digestFrequency($recipient))->toBe('daily');
});

it('builds a preference matrix marking deviations', function (): void {
    $recipient = Identity::user(1);
    $this->preferences->set($recipient, 'community.mention', 'mail', false);

    $matrix = collect($this->preferences->matrixFor($recipient));
    $mention = $matrix->firstWhere('type', 'community.mention');

    expect($mention['label'])->toBe('Erwähnung')
        ->and($mention['channels']['mail']['enabled'])->toBeFalse()
        ->and($mention['channels']['mail']['is_default'])->toBeFalse()
        ->and($mention['channels']['in_app']['is_default'])->toBeTrue();
});
