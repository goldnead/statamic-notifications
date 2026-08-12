<?php

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\BrandContext\Sending\SaidRecently;
use Goldnead\BrandContext\Sending\SenderIdentity;
use Goldnead\IdentityContracts\Identity;
use Goldnead\Notifications\Contracts\SenderIdentityResolver;
use Goldnead\Notifications\Facades\Notifications;
use Goldnead\Notifications\Models\NotificationItem;
use Goldnead\Notifications\Sending\BrandMailer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Who a notification goes out as, and over which transport.
 *
 * `Mail::fake()` is deliberately NOT used in this file. The fake records the
 * name of the mailer but never renders the message, and the From is decided
 * during the render — so the fake can prove the transport and not the sender,
 * which is exactly one half of the bug. Every brand here gets its own `array`
 * transport instead: the assertions then read the real MIME message out of the
 * transport that actually accepted it, which answers both questions with one
 * observation.
 *
 * The bug this file exists for: one process sending for several brands in
 * sequence — a queue worker, a digest run — put every brand's mail through
 * `config('mail.default')` with `config('mail.from')`. The second brand
 * therefore left under the first brand's identity, and a relay that verifies
 * sending domains per account refuses that or rewrites it.
 */
beforeEach(function (): void {
    SaidRecently::forget();

    config()->set('mail.mailers.marke_a', ['transport' => 'array']);
    config()->set('mail.mailers.marke_b', ['transport' => 'array']);
    config()->set('mail.mailers.global', ['transport' => 'array']);
    config()->set('mail.default', 'global');
    config()->set('mail.from.address', 'global@example.com');
    config()->set('mail.from.name', 'Global');

    // The real MIME message, not the SentMessage wrapper: the From is written
    // during the render, so this is where both halves of the identity are
    // observable at once.
    $this->mails = fn (string $mailer) => collect(Mail::mailer($mailer)->getSymfonyTransport()->messages())
        ->map(fn ($sent) => $sent->getOriginalMessage())
        ->values()
        ->all();

    $this->type = function (): void {
        Notifications::registerType('test.ping', function ($type): void {
            $type->label('Ping')->defaultChannels(['mail']);
        });
    };

    $this->ping = fn () => Notifications::notify(
        Identity::user(1, 'someone@example.com'),
        'test.ping',
        ['message' => 'Ping'],
    );
});

/**
 * The line that keeps this addon installable outside the host it was written
 * for. Nothing about a single-brand send may change: same transport, same From,
 * decided by config exactly as before the resolver existed.
 */
it('leaves a single-brand install sending exactly as before', function (): void {
    config()->set('brand-context.multi_brand', false);
    app('brand-context')->forget();
    ($this->type)();

    ($this->ping)();

    $mails = ($this->mails)('global');

    expect($mails)->toHaveCount(1)
        ->and($mails[0]->getFrom()[0]->getAddress())->toBe('global@example.com');
});

/**
 * The same guarantee one layer down: multi-brand is on, but the brand says
 * nothing about mail. An install that has not filled the settings in must not
 * fall silent because of an addon upgrade.
 */
it('leaves a brand without mail settings sending exactly as before', function (): void {
    config()->set('brand-context.multi_brand', true);
    app('brand-context')->forget();
    ($this->type)();

    $brand = Brand::create(['handle' => 'stumm', 'name' => 'Stumm', 'settings' => []]);

    BrandContext::runFor($brand, fn () => ($this->ping)());

    $mails = ($this->mails)('global');

    expect($mails)->toHaveCount(1)
        ->and($mails[0]->getFrom()[0]->getAddress())->toBe('global@example.com');
});

/**
 * THE test. Two brands, one process, the brand with its own mailer first —
 * because that is the only order in which the bug shows. Laravel burns
 * `mail.from` into a mailer instance on first resolution and caches it in the
 * `mail.manager` singleton, so a first send that warms a mailer used to decide
 * the sender for everything after it.
 *
 * Against the pre-12.08.2026 code both notifications land in the `global`
 * transport under `global@example.com`.
 */
it('does not let the first brand in a process decide who the second sends as', function (): void {
    config()->set('brand-context.multi_brand', true);
    app('brand-context')->forget();
    ($this->type)();

    $a = Brand::create(['handle' => 'marke-a', 'name' => 'Marke A', 'settings' => ['mail' => [
        'from_address' => 'noreply@marke-a.test',
        'from_name' => 'Marke A',
        'mailer' => 'marke_a',
    ]]]);

    $b = Brand::create(['handle' => 'marke-b', 'name' => 'Marke B', 'settings' => ['mail' => [
        'from_address' => 'noreply@marke-b.test',
        'mailer' => 'marke_b',
    ]]]);

    foreach ([$a, $b] as $brand) {
        BrandContext::runFor($brand, fn () => ($this->ping)());
    }

    $fromA = ($this->mails)('marke_a');
    $fromB = ($this->mails)('marke_b');

    expect($fromA)->toHaveCount(1)
        ->and($fromA[0]->getFrom()[0]->getAddress())->toBe('noreply@marke-a.test')
        ->and($fromA[0]->getFrom()[0]->getName())->toBe('Marke A')
        ->and($fromB)->toHaveCount(1)
        ->and($fromB[0]->getFrom()[0]->getAddress())->toBe('noreply@marke-b.test')
        // The negative half, and the one that actually matters: the default
        // transport saw nothing at all.
        ->and(($this->mails)('global'))->toHaveCount(0);
});

it('sends nothing for a brand that declares mail settings without a from address', function (): void {
    config()->set('brand-context.multi_brand', true);
    app('brand-context')->forget();
    ($this->type)();

    Log::spy();

    $brand = Brand::create(['handle' => 'halb', 'name' => 'Halb', 'settings' => ['mail' => [
        'mailer' => 'marke_a',
    ]]]);

    BrandContext::runFor($brand, fn () => ($this->ping)());

    expect(($this->mails)('marke_a'))->toHaveCount(0)
        ->and(($this->mails)('global'))->toHaveCount(0);

    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message) => str_contains($message, 'from_address'))
        ->once();

    // The notification itself is not lost — how somebody is reached is a
    // different question from whether the thing happened.
    expect(NotificationItem::withoutGlobalScopes()->count())->toBe(1);
});

it('sends nothing for a brand naming a mailer config does not define', function (): void {
    config()->set('brand-context.multi_brand', true);
    app('brand-context')->forget();
    ($this->type)();

    Log::spy();

    $brand = Brand::create(['handle' => 'tippfehler', 'name' => 'Tippfehler', 'settings' => ['mail' => [
        'from_address' => 'noreply@tippfehler.test',
        'mailer' => 'scaleway_typo',
    ]]]);

    BrandContext::runFor($brand, fn () => ($this->ping)());

    expect(($this->mails)('global'))->toHaveCount(0);

    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message) => str_contains($message, 'scaleway_typo'))
        ->once();
});

/**
 * The digest stamps `digested_at` before it delivers, so "can this brand send"
 * has to be answered in front of the recipient loop. Answering it at the send
 * would leave every recipient's window marked as digested and nothing sent —
 * the items would never resurface.
 */
it('skips a brand that cannot send before the digest stamps anything', function (): void {
    config()->set('brand-context.multi_brand', true);
    app('brand-context')->forget();

    Notifications::registerType('test.digestible', function ($type): void {
        $type->label('Digestible')->defaultChannels(['digest']);
    });

    $brand = Brand::create(['handle' => 'halb', 'name' => 'Halb', 'settings' => ['mail' => [
        'mailer' => 'marke_a',
    ]]]);

    BrandContext::runFor($brand, fn () => Notifications::notify(
        Identity::user(1, 'someone@example.com'),
        'test.digestible',
        ['message' => 'X'],
    ));

    $this->artisan('notifications:send-digests', ['--frequency' => 'weekly'])->assertSuccessful();

    $item = NotificationItem::withoutGlobalScopes()->first();

    expect(($this->mails)('marke_a'))->toHaveCount(0)
        ->and(($this->mails)('global'))->toHaveCount(0)
        ->and($item->digested_at)->toBeNull();
});

it('lets a host swap the resolver without touching the addon', function (): void {
    config()->set('brand-context.multi_brand', false);
    app('brand-context')->forget();
    ($this->type)();

    app()->bind(
        SenderIdentityResolver::class,
        fn () => new class implements SenderIdentityResolver
        {
            public function resolve(?int $brandId): SenderIdentity
            {
                return SenderIdentity::of('marke_b', 'host@example.test', 'Host');
            }
        },
    );

    ($this->ping)();

    $mails = ($this->mails)('marke_b');

    expect($mails)->toHaveCount(1)
        ->and($mails[0]->getFrom()[0]->getAddress())->toBe('host@example.test');
});

/**
 * A fan-out asks "can this brand send" once per recipient. Unthrottled, a
 * misconfigured brand writes one identical error line per message, which is how
 * a real warning gets scrolled past. Re-armed rather than silenced: a
 * `queue:work` runs for days, and every one of these lines means mail is not
 * going out.
 */
it('says a refusal once per window rather than once per recipient', function (): void {
    expect(SaidRecently::shouldSay('x'))->toBeTrue()
        ->and(SaidRecently::shouldSay('x'))->toBeFalse()
        ->and(SaidRecently::shouldSay('y'))->toBeTrue();

    SaidRecently::forget();

    expect(SaidRecently::shouldSay('x'))->toBeTrue();
});

it('refuses a queued mailable rather than losing the identity silently', function (): void {
    $mailer = app(BrandMailer::class);

    $queued = new class extends Mailable implements ShouldQueue
    {
        public function build(): self
        {
            return $this->html('x');
        }
    };

    expect(fn () => $mailer->send(null, 'a@example.com', null, $queued))
        ->toThrow(LogicException::class);
});

it('does not touch mail.from config while sending', function (): void {
    config()->set('brand-context.multi_brand', true);
    app('brand-context')->forget();
    ($this->type)();

    $brand = Brand::create(['handle' => 'marke-a', 'name' => 'Marke A', 'settings' => ['mail' => [
        'from_address' => 'noreply@marke-a.test',
        'mailer' => 'marke_a',
    ]]]);

    BrandContext::runFor($brand, fn () => ($this->ping)());

    // Not cosmetics. A `Config::set('mail.from.…')` here would survive its own
    // `finally`, because Laravel has already burned the value into the cached
    // mailer instance by the time the window closes.
    expect(config('mail.from.address'))->toBe('global@example.com')
        ->and(config('mail.default'))->toBe('global');
});
