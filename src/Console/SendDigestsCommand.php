<?php

namespace Goldnead\Notifications\Console;

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\IdentityContracts\Identity;
use Goldnead\Notifications\Contracts\RecipientDirectory;
use Goldnead\Notifications\Digest\DigestBuilder;
use Goldnead\Notifications\Mail\DigestMail;
use Goldnead\Notifications\Preferences\PreferenceResolver;
use Goldnead\Notifications\Sending\BrandMailer;
use Goldnead\Suppression\Contracts\Gate as SuppressionGate;
use Goldnead\Suppression\Exceptions\SuppressionCheckFailed;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * One process, every brand, in sequence — which is exactly the shape in which
 * a process-wide sender identity does its damage. Until 12.08.2026 the send
 * line was `Mail::to(...)->send(new DigestMail(...))`: default mailer, global
 * From, identical for brand one and brand five. It now goes through
 * {@see BrandMailer}, and a brand that cannot produce a usable identity is
 * skipped *before* anything is stamped.
 */
class SendDigestsCommand extends Command
{
    protected $signature = 'notifications:send-digests
                            {--frequency=weekly : daily or weekly}
                            {--brand= : Restrict to one brand handle or id (default: every brand)}
                            {--now= : Treat this timestamp as "now" (testing and catch-up runs)}
                            {--dry-run : Report what would be sent without sending or marking}';

    protected $description = 'Send notification digests for the current window.';

    public function handle(
        DigestBuilder $builder,
        PreferenceResolver $preferences,
        RecipientDirectory $directory,
    ): int {
        $frequency = (string) $this->option('frequency');

        if (! in_array($frequency, ['daily', 'weekly'], true)) {
            $this->components->error("Unknown frequency [{$frequency}]. Use daily or weekly.");

            return self::FAILURE;
        }

        // A console run has no CP session, so no brand is current. Under
        // multi-brand the global scope then fails closed and every query returns
        // nothing — a scheduled digest would silently send zero mails forever.
        // So the command walks the brands itself.
        foreach ($this->brands() as $brand) {
            if ($brand !== null) {
                $this->line("Brand: {$brand->handle}");
            }

            $exit = BrandContext::runFor($brand ?? BrandContext::defaultId(), fn () => $this->sendFor(
                $builder, $preferences, $directory, $frequency, $brand
            ));

            if ($exit !== self::SUCCESS) {
                return $exit;
            }
        }

        return $this->lost > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Digests stamped as sent that never left, across every brand of this run.
     *
     * The one outcome a scheduler must not read as a quiet success: those
     * recipients' windows are marked as digested and nothing arrived, and
     * nothing brings them back.
     */
    protected int $lost = 0;

    /** @return iterable<Brand|null> */
    protected function brands(): iterable
    {
        if (! BrandContext::multiBrandEnabled()) {
            return [null];
        }

        if ($handle = $this->option('brand')) {
            return [Brand::query()->where('handle', $handle)->orWhere('id', $handle)->firstOrFail()];
        }

        return Brand::query()->orderBy('id')->get();
    }

    protected function sendFor(
        DigestBuilder $builder,
        PreferenceResolver $preferences,
        RecipientDirectory $directory,
        string $frequency,
        ?Brand $brand = null,
    ): int {

        $now = $this->option('now') ? Carbon::parse($this->option('now')) : null;
        $dryRun = (bool) $this->option('dry-run');

        $mailer = app(BrandMailer::class);
        $brandId = $brand?->getKey() === null ? null : (int) $brand->getKey();

        // In front of the loop, and that placement is the point.
        //
        // `markSent()` stamps `digested_at` on every collected item before the
        // mail leaves. A brand whose sender identity is unusable would
        // therefore burn each recipient's whole window — items marked
        // delivered, nothing delivered, and nothing resurfaced on the next run.
        // Asking once here costs one resolve and loses nothing: everything
        // stays pending until the identity is fixed.
        if (! $mailer->maySend($brandId)) {
            $this->components->warn(sprintf(
                'Brand [%s]: no usable sender identity. See the log.',
                $brand === null ? 'default' : $brand->handle,
            ));

            // A dry run answers "what would this send" and must not answer it
            // for a brand that cannot send at all — but it also must not stop
            // at the first broken brand, because reporting the rest is the
            // whole point of a dry run. So it says the sentence and moves on;
            // a real run leaves without collecting or stamping anything.
            if (! $dryRun) {
                return self::SUCCESS;
            }
        }

        $sent = 0;
        $skippedEmpty = 0;
        $skippedAlreadySent = 0;
        $skippedSuppressed = 0;
        $lost = 0;

        foreach ($directory->digestRecipients($frequency) as $recipient) {
            if (! $recipient instanceof Identity || ! $recipient->isIdentified()) {
                continue;
            }

            // A recipient who wants weekly must not be caught by the daily run.
            if ($preferences->digestFrequency($recipient) !== $frequency) {
                continue;
            }

            // Gated *before* markSent(), which is the only placement that works.
            //
            // markSent() stamps `digested_at` on every collected item and writes
            // the run row for the window. Checking after it would mean a
            // suppressed recipient's items are burned — marked as digested,
            // never delivered, and never resurfaced if the suppression is later
            // released. Skipping here leaves them pending, so the next run after
            // a release picks them up.
            if ($this->isSuppressed($recipient)) {
                $skippedSuppressed++;

                continue;
            }

            $collected = $builder->collect($recipient, $frequency, $now);

            if ($builder->isEmpty($collected)) {
                $skippedEmpty++;

                continue;
            }

            if ($dryRun) {
                $this->line(sprintf('  would send %d item(s) to %s', $collected['items']->count(), $recipient->email ?? $recipient->id));
                $sent++;

                continue;
            }

            // Mark first: a crash after sending must not risk a second send,
            // and the run row is the thing that makes this idempotent.
            $run = $builder->markSent($recipient, $frequency, $collected);

            if ($run === null) {
                $skippedAlreadySent++;

                continue;
            }

            if ($recipient->email !== null && $recipient->email !== '') {
                // The per-brand guard above covers the ordinary case, and it
                // has to, because it is the only check that runs before the
                // stamp. This one catches the narrow window it cannot: a brand
                // row edited during a long run, or a host resolver that answers
                // differently over time. The items are already stamped by then,
                // so nothing can be undone — but a run that stamped a week of
                // items and delivered nothing must not report success.
                if (! $mailer->send(
                    $brandId,
                    $recipient->email,
                    $recipient->name,
                    new DigestMail($recipient, $collected, $frequency),
                )) {
                    $lost++;

                    continue;
                }
            }

            $sent++;
        }

        $this->components->info(sprintf(
            '%s %d digest(s). Skipped: %d empty, %d already sent for this window, %d suppressed.',
            $dryRun ? 'Would send' : 'Sent',
            $sent,
            $skippedEmpty,
            $skippedAlreadySent,
            $skippedSuppressed,
        ));

        if ($lost > 0) {
            $this->components->error(sprintf(
                '%d digest(s) were marked as sent but could not be delivered: the sender identity became '
                .'unusable mid-run. Those items will not resurface.',
                $lost,
            ));

            // Counted rather than returned. A non-SUCCESS exit here would stop
            // `handle()` before the remaining brands, and one brand's broken
            // row must never cost the others their weekly mail. The run ends
            // non-zero once, at the end, where a scheduler still sees it.
            $this->lost += $lost;
        }

        return self::SUCCESS;
    }

    /**
     * Is this recipient's address blocked from every send path?
     *
     * Fails closed. A digest is not urgent, and "the suppression list could not
     * be read" is not permission to write to a mailbox that may have complained.
     * The items stay pending either way, so nothing is lost by waiting for the
     * next run.
     */
    protected function isSuppressed(Identity $recipient): bool
    {
        $address = $recipient->email;

        if ($address === null || $address === '') {
            return false;
        }

        try {
            return app(SuppressionGate::class)->isSuppressed($address);
        } catch (SuppressionCheckFailed $e) {
            report($e);

            return true;
        }
    }
}
