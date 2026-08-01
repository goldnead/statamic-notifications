<?php

namespace Goldnead\Notifications\Console;

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\IdentityContracts\Identity;
use Goldnead\Notifications\Contracts\RecipientDirectory;
use Goldnead\Notifications\Digest\DigestBuilder;
use Goldnead\Notifications\Mail\DigestMail;
use Goldnead\Notifications\Preferences\PreferenceResolver;
use Goldnead\Suppression\Contracts\Gate as SuppressionGate;
use Goldnead\Suppression\Exceptions\SuppressionCheckFailed;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

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
                $builder, $preferences, $directory, $frequency
            ));

            if ($exit !== self::SUCCESS) {
                return $exit;
            }
        }

        return self::SUCCESS;
    }

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
    ): int {

        $now = $this->option('now') ? Carbon::parse($this->option('now')) : null;
        $dryRun = (bool) $this->option('dry-run');

        $sent = 0;
        $skippedEmpty = 0;
        $skippedAlreadySent = 0;
        $skippedSuppressed = 0;

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
                Mail::to($recipient->email)->send(new DigestMail($recipient, $collected, $frequency));
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
