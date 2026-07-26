<?php

namespace Goldnead\Notifications\Console;

use Goldnead\IdentityContracts\Identity;
use Goldnead\Notifications\Contracts\RecipientDirectory;
use Goldnead\Notifications\Digest\DigestBuilder;
use Goldnead\Notifications\Mail\DigestMail;
use Goldnead\Notifications\Preferences\PreferenceResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

class SendDigestsCommand extends Command
{
    protected $signature = 'notifications:send-digests
                            {--frequency=weekly : daily or weekly}
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

        $now = $this->option('now') ? Carbon::parse($this->option('now')) : null;
        $dryRun = (bool) $this->option('dry-run');

        $sent = 0;
        $skippedEmpty = 0;
        $skippedAlreadySent = 0;

        foreach ($directory->digestRecipients($frequency) as $recipient) {
            if (! $recipient instanceof Identity || ! $recipient->isIdentified()) {
                continue;
            }

            // A recipient who wants weekly must not be caught by the daily run.
            if ($preferences->digestFrequency($recipient) !== $frequency) {
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
            '%s %d digest(s). Skipped: %d empty, %d already sent for this window.',
            $dryRun ? 'Would send' : 'Sent',
            $sent,
            $skippedEmpty,
            $skippedAlreadySent,
        ));

        return self::SUCCESS;
    }
}
