<?php

namespace Goldnead\Notifications\Sending;

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\Notifications\Contracts\SenderIdentityResolver;
use Throwable;

/**
 * Reads the sender identity out of `brands.settings.mail`.
 *
 * ```
 * settings->mail->from_address   (required once `mail` is present at all)
 * settings->mail->from_name      (defaults to the brand name)
 * settings->mail->mailer         (a mailer from config/mail.php)
 * settings->mail->locale         (the language its mail is written in)
 * ```
 *
 * **Why the transport belongs here and the credentials do not.** One relay
 * account verifies one set of sending domains. This was learned the expensive
 * way: a brand whose domain lived in a different Scaleway project sent through
 * the account that did not own it, and the provider substituted its own
 * verified From — the mail went out under a *foreign brand's* name. A mailer
 * per sending domain in `config/mail.php`, selected per brand here, is what
 * prevents that. The SMTP credentials stay in the environment; putting them in
 * `settings` would carry them into the database, every backup and every CP
 * export.
 *
 * **A brand with no `settings.mail` changes nothing.** It resolves to the pure
 * config identity, so a single-brand install — and every multi-brand install
 * that has not filled the settings in — sends exactly as it did before this
 * class existed. Only once a brand declares a mail identity do the brand-level
 * defaults (from_name ← brand name) apply. This is the line that keeps the
 * addon installable outside the host it was written for, and it is covered by
 * a test of its own.
 *
 * **A brand that declares `mail` but no `from_address` sends nothing.** That
 * is the one case where this class is louder than "unchanged": the brand said
 * it has an identity and then did not give the half that the relay checks.
 * Using the host-wide From instead would put a brand's transport behind
 * somebody else's address — deliverable only if the two happen to belong to
 * the same account, and silently wrong when they do not. Refusing is the
 * smaller failure and it is the visible one.
 *
 * **A mailer name that `config/mail.php` does not define sends nothing either**,
 * and it is caught here rather than at the send. In a digest the send is the
 * wrong place: `DigestBuilder::markSent()` stamps `digested_at` before the mail
 * leaves, so a typo discovered at delivery time costs every recipient their
 * week of items with nothing sent.
 */
class BrandSenderIdentity implements SenderIdentityResolver
{
    public function resolve(?int $brandId): SenderIdentity
    {
        $brand = $this->brand($brandId);

        if (! $brand) {
            return SenderIdentity::fromConfig();
        }

        $mail = data_get($brand->settings, 'mail');
        $mail = is_array($mail) ? array_filter($mail, fn ($v) => $v !== null && $v !== '') : [];

        if ($mail === []) {
            // The brand exists but says nothing about mail. Nothing changes —
            // including the locale, which stays whatever the app decided.
            return SenderIdentity::fromConfig();
        }

        $address = $this->string(data_get($mail, 'from_address'));

        if ($address === null) {
            return SenderIdentity::refusing(sprintf(
                'Brand [%s] declares settings.mail but no settings.mail.from_address. Nothing was sent, '
                .'because the mail would otherwise have gone out under the host-wide From — in a '
                .'multi-brand install, under another brand\'s identity. Set from_address on the brand.',
                $this->label($brand),
            ));
        }

        $mailer = $this->string(data_get($mail, 'mailer'));

        // Deliberately only checked for a mailer the brand named itself. The
        // configured default is the application's own business and must not
        // start refusing mail because of an addon update.
        if ($mailer !== null && ! is_array(config('mail.mailers.'.$mailer))) {
            return SenderIdentity::refusing(sprintf(
                'Brand [%s] names the mailer [%s], which config/mail.php does not define. Nothing was '
                .'sent. Either add the mailer to config/mail.php (with its credentials in the '
                .'environment) or correct settings.mail.mailer on the brand.',
                $this->label($brand),
                $mailer,
            ));
        }

        return SenderIdentity::of(
            mailer: $mailer,
            fromAddress: $address,
            fromName: $this->string(data_get($mail, 'from_name')) ?: $this->string($brand->name),
            locale: $this->string(data_get($mail, 'locale')) ?: $this->string(data_get($brand->settings, 'locale')),
        );
    }

    /**
     * The brand a mail belongs to, or null when there is nothing to look up.
     *
     * Fails soft by design: a missing brands table (an install mid-migration),
     * a deleted brand, a queue worker with no brand in context — none of those
     * may stop a mail that would have gone out before. They all mean "use the
     * configured identity".
     */
    protected function brand(?int $brandId): ?Brand
    {
        try {
            if ($brandId !== null) {
                // Brands are the scoping root and carry no brand scope of
                // their own, so this needs no escape hatch.
                return Brand::query()->find($brandId);
            }

            return BrandContext::hasCurrent() ? BrandContext::current() : null;
        } catch (Throwable $e) {
            // Throttled for the same reason as everything else here: a database
            // unreachable during a fan-out is one incident, not fifty thousand
            // of them. Keyed by exception class and re-armed after the window,
            // NOT silenced for the life of the process — a `queue:work` runs
            // for days, and this path answers a failed lookup by falling back
            // to the global identity. That is precisely the mis-attribution
            // this class exists to prevent, so the second, different failure
            // has to be as visible as the first.
            if (SaidRecently::shouldSay('lookup:'.$e::class)) {
                report($e);
            }

            return null;
        }
    }

    protected function label(Brand $brand): string
    {
        return (string) ($brand->handle ?? $brand->getKey() ?? '?');
    }

    protected function string(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return trim($value) === '' ? null : trim($value);
    }
}
