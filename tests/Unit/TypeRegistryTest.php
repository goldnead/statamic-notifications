<?php

use Goldnead\Notifications\Facades\Notifications;
use Goldnead\Notifications\Models\NotificationItem;
use Goldnead\Notifications\Types\NotificationType;
use Goldnead\Notifications\Types\TypeRegistry;

beforeEach(function (): void {
    $this->registry = app(TypeRegistry::class);
});

it('returns a usable definition for an unregistered type', function (): void {
    // A missing registration must never swallow somebody's notification.
    $type = $this->registry->get('never.registered');

    expect($type)->toBeInstanceOf(NotificationType::class)
        ->and($type->handle)->toBe('never.registered')
        ->and($type->defaultChannels)->toBe(['in_app'])
        ->and($this->registry->has('never.registered'))->toBeFalse();
});

it('renders through the registered renderer', function (): void {
    Notifications::registerType('community.reply', function ($type): void {
        $type->label('Antwort')->renderUsing(fn (NotificationItem $item) => [
            'message' => $item->actor_name.' hat geantwortet.',
            'link' => '/posts/'.$item->subject_id,
        ]);
    });

    $item = new NotificationItem([
        'type' => 'community.reply',
        'actor_name' => 'Bea',
        'subject_id' => '17',
    ]);

    $rendered = $this->registry->get('community.reply')->render($item);

    expect($rendered['message'])->toBe('Bea hat geantwortet.')
        ->and($rendered['link'])->toBe('/posts/17')
        ->and($rendered['title'])->toBe('Antwort');
});

it('falls back to the stored message when no renderer is set', function (): void {
    Notifications::registerType('community.mention');

    $item = new NotificationItem(['type' => 'community.mention', 'message' => 'gespeichert', 'link' => '/x']);

    $rendered = $this->registry->get('community.mention')->render($item);

    expect($rendered['message'])->toBe('gespeichert')
        ->and($rendered['link'])->toBe('/x');
});

it('replaces a definition when the same handle is registered again', function (): void {
    Notifications::registerType('a.b', fn ($type) => $type->label('erst'));
    Notifications::registerType('a.b', fn ($type) => $type->label('dann'));

    expect($this->registry->all())->toHaveCount(1)
        ->and($this->registry->get('a.b')->label)->toBe('dann');
});

it('knows which channels a type uses by default', function (): void {
    $type = NotificationType::make('x')->defaultChannels(['in_app', 'digest']);

    expect($type->usesChannel('digest'))->toBeTrue()
        ->and($type->usesChannel('mail'))->toBeFalse();
});
