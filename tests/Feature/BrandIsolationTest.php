<?php

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\IdentityContracts\Identity;
use Goldnead\Notifications\Digest\DigestBuilder;
use Goldnead\Notifications\Facades\Notifications;
use Goldnead\Notifications\Models\NotificationItem;
use Goldnead\Notifications\Preferences\PreferenceResolver;

beforeEach(function (): void {
    $this->enableMultiBrand();
    $this->brandA = $this->makeBrand('brand-a');
    $this->brandB = $this->makeBrand('brand-b');
});

afterEach(function (): void {
    BrandContext::withoutBrandScope(fn () => NotificationItem::query()->delete());
});

it('hides a notification written in brand A from brand B', function (): void {
    $inA = BrandContext::runFor($this->brandA, fn () => Notifications::notify(Identity::user(1), 'community.mention'));

    expect($inA->brand_id)->toBe($this->brandA->id);

    BrandContext::setCurrent($this->brandB);

    expect(NotificationItem::find($inA->id))->toBeNull()
        ->and(Notifications::unreadCount(Identity::user(1)))->toBe(0);

    BrandContext::setCurrent($this->brandA);

    expect(Notifications::unreadCount(Identity::user(1)))->toBe(1);
});

it('lets the same person hold independent notifications per brand', function (): void {
    foreach ([$this->brandA, $this->brandB] as $brand) {
        BrandContext::runFor($brand, fn () => Notifications::notify(Identity::user(1), 'community.mention', [
            'dedupe_key' => 'mention:shared',
        ]));
    }

    expect(BrandContext::withoutBrandScope(fn () => NotificationItem::count()))->toBe(2);
});

it('keeps preferences brand-scoped', function (): void {
    $preferences = app(PreferenceResolver::class);
    $recipient = Identity::user(1);

    Notifications::registerType('community.mention', fn ($type) => $type->defaultChannels(['in_app', 'mail']));

    BrandContext::runFor($this->brandA, fn () => $preferences->set($recipient, 'community.mention', 'mail', false));

    BrandContext::setCurrent($this->brandB);

    // Opting out in one brand must not silence the other.
    expect($preferences->allows($recipient, 'community.mention', 'mail'))->toBeTrue();

    BrandContext::setCurrent($this->brandA);

    expect($preferences->allows($recipient, 'community.mention', 'mail'))->toBeFalse();
});

it('keeps digest runs brand-scoped', function (): void {
    $builder = app(DigestBuilder::class);
    $recipient = Identity::user(1, 'a@example.com');

    BrandContext::runFor($this->brandA, function () use ($builder, $recipient): void {
        Notifications::notify($recipient, 'community.reply');
        $collected = $builder->collect($recipient, 'weekly');
        expect($builder->markSent($recipient, 'weekly', $collected))->not->toBeNull();
    });

    // The same window in another brand is a different send.
    BrandContext::runFor($this->brandB, function () use ($builder, $recipient): void {
        Notifications::notify($recipient, 'community.reply');
        $collected = $builder->collect($recipient, 'weekly');
        expect($builder->markSent($recipient, 'weekly', $collected))->not->toBeNull();
    });
});

it('fails closed when no brand is current', function (): void {
    BrandContext::runFor($this->brandA, fn () => Notifications::notify(Identity::user(1), 'community.mention'));

    BrandContext::forget();

    expect(NotificationItem::count())->toBe(0);
});

it('never matches every row for a recipient with no join keys', function (): void {
    BrandContext::runFor($this->brandA, fn () => Notifications::notify(Identity::user(1), 'community.mention'));

    BrandContext::setCurrent($this->brandA);

    expect(NotificationItem::forRecipient(Identity::system())->count())->toBe(0)
        ->and(NotificationItem::count())->toBe(1);
});
