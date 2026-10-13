<?php

namespace Goldnead\Notifications\Sending;

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\Notifications\Channels\ChannelRegistry;
use Goldnead\Notifications\Contracts\DeferredChannel;
use Goldnead\Notifications\Contracts\DeferredRecipientResolver;
use Goldnead\Notifications\Models\NotificationDelivery;
use Goldnead\Notifications\Models\NotificationItem;
use Goldnead\Notifications\Preferences\PreferenceResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The bounded worker behind `notifications:dispatch-pending`.
 *
 * ## The claim
 * Each run takes at most `$limit` claimable rows (pending or retry, due),
 * oldest first. Each row is then re-read under `lockForUpdate()` inside the
 * transaction that also records its outcome, and re-checked: the claim list
 * was assembled without a lock, and between the list and the lock another
 * worker may have sent the row. The lock plus the recheck is what serializes
 * workers — two runs can share a claim list, never a send.
 *
 * ## The lock is held through the send
 * The transaction stays open across the channel call, so an SMTP send holds
 * a database row lock for its duration. That is the price of the simpler
 * invariant above; the counterpart is keeping runs bounded (`--limit`) so a
 * slow transport cannot pile up locks. If that trade stops being acceptable,
 * the claim needs a `processing` state of its own — added deliberately late,
 * because it brings back exactly the failure mode (rows stuck in
 * "processing" after a crash) this shape avoids for free.
 *
 * ## At-least-once
 * A crash after the transport accepted the mail but before this process
 * committed leaves the row claimable, and the next run sends again. Within
 * that window a duplicate mail is possible; a lost notification is not. What
 * `accepted` records is acceptance by the transport — never inbox delivery.
 *
 * ## Nothing personal is stored
 * Rows carry a fixed outcome vocabulary. Exceptions out of a channel are
 * reported (the handler keeps the trace) and reduced to `transport_error` on
 * the row; no message text, no address, no stack.
 */
class DeferredDelivery
{
    /** The dispatcher's own outcome codes, beside the channel's. */
    public const CODE_RECIPIENT_UNRESOLVABLE = 'recipient_unresolvable';

    public const CODE_PREFERENCE_DISABLED = 'preference_disabled';

    public const CODE_CHANNEL_UNKNOWN = 'channel_unknown';

    public const CODE_CHANNEL_NOT_DEFERRED = 'channel_not_deferred';

    public function __construct(
        protected ChannelRegistry $channels,
        protected PreferenceResolver $preferences,
        protected DeferredRecipientResolver $recipients,
    ) {}

    /**
     * Send what is due, up to `$limit` rows, and say what happened.
     *
     * @return array{claimed: int, accepted: int, retry: int, skipped: int, cancelled: int, failed: int}
     */
    public function dispatchPending(int $limit = 100): array
    {
        $counts = ['claimed' => 0, 'accepted' => 0, 'retry' => 0, 'skipped' => 0, 'cancelled' => 0, 'failed' => 0];

        // Rows were created while the addon was enabled; a run that starts
        // while it is disabled consumes nothing. The rows keep waiting for a
        // run after it is back on.
        if ($limit < 1 || ! config('notifications.enabled', true)) {
            return $counts;
        }

        foreach ($this->claimBatch($limit) as $delivery) {
            // Without the global scope and ahead of the brand switch: under
            // multi-brand, the item's brand is the only authority on which
            // brand this send belongs to — never the ambient one in this
            // worker process.
            $item = NotificationItem::withoutGlobalScopes()->find($delivery->notification_item_id);

            if ($item === null) {
                // The foreign key deletes deliveries with their item, so this
                // is a passing inconsistency, not a send to make.
                continue;
            }

            // Row-scoped, restored in a finally inside runFor(): every query
            // and every send below answers to this row's brand, and the
            // worker's ambient brand is back the moment the row is done.
            $outcome = BrandContext::runFor(
                (int) $item->brand_id,
                fn (): array => $this->dispatchOne($delivery, $item),
            );

            foreach ($outcome as $bucket => $count) {
                $counts[$bucket] += $count;
            }
        }

        return $counts;
    }

    /**
     * The claim list: due rows, oldest first, bounded.
     *
     * @return Collection<int, NotificationDelivery>
     */
    protected function claimBatch(int $limit): Collection
    {
        return NotificationDelivery::query()
            ->claimable()
            ->orderBy('available_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * One row, locked, rechecked, sent, recorded — all in one transaction.
     *
     * @return array<string, int> the count buckets this row landed in; empty
     *                            when the row turned out not to be ours to send
     */
    protected function dispatchOne(NotificationDelivery $delivery, NotificationItem $item): array
    {
        return DB::transaction(function () use ($delivery, $item): array {
            $row = NotificationDelivery::query()->lockForUpdate()->find($delivery->getKey());

            if ($row === null || ! $row->isClaimable()) {
                // Another worker finished it, or it entered its backoff
                // window between the list and the lock. Not ours, not
                // counted.
                return [];
            }

            if (! config('notifications.enabled', true)) {
                // Switched off mid-run: the row stays exactly as it was.
                return [];
            }

            $identity = $this->recipients->resolve($item);

            if ($identity === null || ! $identity->isIdentified()) {
                // The resolver's null is a decision: this recipient cannot be
                // reached through any record we trust, and no address stored
                // on the item is ever consulted as a substitute.
                return $this->finish($row, NotificationDelivery::STATUS_CANCELLED, self::CODE_RECIPIENT_UNRESOLVABLE);
            }

            // Preferences were honoured when the row was written; they are
            // honoured again now, because "who wants what" may have moved
            // between the fact and the send. Off means off: the row ends
            // skipped, and does not come back if the preference returns.
            if (! $this->preferences->allows($identity, $item->type, $row->channel)) {
                return $this->finish($row, NotificationDelivery::STATUS_SKIPPED, self::CODE_PREFERENCE_DISABLED);
            }

            try {
                $channel = $this->channels->resolve($row->channel);
            } catch (\InvalidArgumentException $e) {
                // Unregistered between creation and dispatch: an addon
                // removed, a config edited. Not sent, not retried in the
                // dark — recorded, so it cannot come back half-configured.
                report($e);

                return $this->finish($row, NotificationDelivery::STATUS_SKIPPED, self::CODE_CHANNEL_UNKNOWN);
            }

            if (! $channel instanceof DeferredChannel) {
                // The registry's answer no longer speaks deferred. Without
                // sendDeferred() there is no outcome to trust, and a void
                // send() is not acceptance.
                return $this->finish($row, NotificationDelivery::STATUS_SKIPPED, self::CODE_CHANNEL_NOT_DEFERRED);
            }

            try {
                $outcome = $channel->sendDeferred($item, $identity);
            } catch (Throwable $e) {
                // Expected failures arrive as outcomes; an exception is a
                // transport error. report() keeps the trace out of the row.
                report($e);

                return $this->recordFailure($row, DeferredChannelOutcome::CODE_TRANSPORT_ERROR);
            }

            if ($outcome->isAccepted()) {
                return $this->finish(
                    $row,
                    NotificationDelivery::STATUS_ACCEPTED,
                    $outcome->outcomeCode,
                    ['accepted_at' => now()],
                );
            }

            if ($outcome->isSkipped()) {
                return $this->finish($row, NotificationDelivery::STATUS_SKIPPED, $outcome->outcomeCode);
            }

            return $this->recordFailure($row, $outcome->outcomeCode);
        });
    }

    /**
     * A failed attempt: the counter rises, the delay is a bounded exponential
     * (base × 2^(attempts-1)), and once the configured maximum is reached the
     * row is failed and stays failed — nothing ever resets a terminal row.
     *
     * @return array<string, int>
     */
    protected function recordFailure(NotificationDelivery $row, string $outcomeCode): array
    {
        $attempts = (int) $row->attempts + 1;
        $terminal = $attempts >= $this->maxAttempts();

        return $this->finish(
            $row,
            $terminal ? NotificationDelivery::STATUS_FAILED : NotificationDelivery::STATUS_RETRY,
            $outcomeCode,
            [
                'attempts' => $attempts,
                // On a terminal row the last due time stays as it was; it is
                // never consulted again, and rewriting it would blur "when
                // this was last due" for anyone reading the table.
                'available_at' => $terminal ? $row->available_at : $this->backoffUntil($attempts),
            ],
        );
    }

    /**
     * Write the outcome. One save, every field, under the row's lock.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, int>
     */
    protected function finish(NotificationDelivery $row, string $status, string $outcomeCode, array $extra = []): array
    {
        $row->forceFill($extra + [
            'status' => $status,
            'outcome_code' => $outcomeCode,
        ])->save();

        $bucket = match ($status) {
            NotificationDelivery::STATUS_ACCEPTED => 'accepted',
            NotificationDelivery::STATUS_SKIPPED => 'skipped',
            NotificationDelivery::STATUS_CANCELLED => 'cancelled',
            NotificationDelivery::STATUS_FAILED => 'failed',
            default => 'retry',
        };

        return [$bucket => 1];
    }

    protected function maxAttempts(): int
    {
        return max(1, (int) config('notifications.deferred.max_attempts', 5));
    }

    /**
     * The delay after the nth failed attempt: base × 2^(n-1), so the first
     * retry waits one base interval, and each further failure doubles it.
     * Bounded by the attempt maximum, which is what keeps a misconfigured
     * `max_attempts` from producing astronomical timestamps.
     */
    protected function backoffUntil(int $attempts): Carbon
    {
        $base = max(0, (int) config('notifications.deferred.backoff_seconds', 60));

        $seconds = (int) min($base * (2 ** max(0, $attempts - 1)), PHP_INT_MAX - 60);

        return now()->addSeconds($seconds);
    }
}
