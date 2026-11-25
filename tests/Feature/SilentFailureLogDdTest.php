<?php

use Goldnead\IdentityContracts\Identity;
use Goldnead\Notifications\Digest\SourceRegistry;
use Goldnead\Notifications\Facades\Notifications;
use Goldnead\Notifications\Preferences\PreferenceResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Failures that vanished into report() or a bare catch: the log now names
 * what failed (type, dedupe key, source handle), so a missing section or a
 * lost notification can be traced.
 */
it('logs type and dedupe key when a notification cannot be stored', function (): void {
    Log::spy();
    Schema::drop('notification_items');

    $item = Notifications::notify(Identity::user(5, 'a@example.com'), 'community.reply', ['dedupe_key' => 'reply:7']);

    expect($item)->toBeNull();

    Log::shouldHaveReceived('error')->withArgs(
        fn ($message, $context = []) => str_contains((string) $message, 'could not be stored')
            && ($context['type'] ?? null) === 'community.reply'
            && ($context['dedupe_key'] ?? null) === 'reply:7'
    )->once();
});

it('logs the handle of a digest source that throws', function (): void {
    Log::spy();
    Notifications::registerSource('kaputt', fn () => throw new RuntimeException('quelle weg'));

    $collected = app(SourceRegistry::class)->collect(Identity::user(5, 'a@example.com'), Carbon::now()->subWeek(), Carbon::now());

    expect($collected)->toBe([]);

    Log::shouldHaveReceived('warning')->withArgs(
        fn ($message, $context = []) => str_contains((string) $message, 'digest source')
            && ($context['source'] ?? null) === 'kaputt'
    )->once();
});

it('logs the type whose appliesTo check throws', function (): void {
    Log::spy();
    Notifications::registerType('community.mention', function ($type): void {
        $type->label('Erwähnung')->appliesTo(fn () => throw new RuntimeException('pruefung kaputt'));
    });

    $matrix = collect(app(PreferenceResolver::class)->matrixFor(Identity::user(2)));

    expect($matrix->firstWhere('type', 'community.mention'))->toBeNull();

    Log::shouldHaveReceived('warning')->withArgs(
        fn ($message, $context = []) => str_contains((string) $message, 'appliesTo')
            && ($context['type'] ?? null) === 'community.mention'
    )->once();
});
