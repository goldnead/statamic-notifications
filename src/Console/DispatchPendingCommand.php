<?php

namespace Goldnead\Notifications\Console;

use Goldnead\Notifications\Sending\DeferredDelivery;
use Illuminate\Console\Command;

/**
 * The bounded manual worker for deferred deliveries.
 *
 * Each run claims at most `--limit` due rows and sends them — real sends,
 * through the channels. The addon schedules nothing: like the digest command,
 * the cadence belongs to the host, so the send window matches its audience
 * and its mail reputation, not ours. An unbounded worker would also hold row
 * locks for as long as the transport is slow, which is why the limit exists
 * at all.
 */
class DispatchPendingCommand extends Command
{
    protected $signature = 'notifications:dispatch-pending
                            {--limit=100 : Maximum number of delivery rows to process in this run}';

    protected $description = 'Dispatch pending deferred notification deliveries.';

    public function handle(DeferredDelivery $deliveries): int
    {
        $counts = $deliveries->dispatchPending(max(1, (int) $this->option('limit')));

        $this->components->info(sprintf(
            'Processed %d deferred delivery row(s): %d accepted, %d scheduled for retry, %d skipped, %d cancelled, %d failed.',
            $counts['claimed'],
            $counts['accepted'],
            $counts['retry'],
            $counts['skipped'],
            $counts['cancelled'],
            $counts['failed'],
        ));

        return self::SUCCESS;
    }
}
