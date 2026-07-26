<?php

namespace Goldnead\Notifications\Digest;

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\IdentityContracts\Identity;
use Goldnead\Notifications\Models\NotificationDigestRun;
use Goldnead\Notifications\Models\NotificationItem;
use Goldnead\Notifications\Preferences\PreferenceResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Assembles one digest for one recipient and one window.
 *
 * The two things the system this replaces was missing:
 *
 *   1. **A window.** The old digest took "everything currently unread", which
 *      is unbounded and has nothing to do with the period being reported.
 *   2. **A record of the send.** Without it there is no way to answer "did this
 *      person already get these items?", so an unread item went out again every
 *      single week for as long as it stayed unread.
 *
 * Both are enforced here and at the database level: `notification_digest_runs`
 * is unique on (brand, recipient, frequency, window_start), and each collected
 * item is stamped with `digested_at`.
 */
class DigestBuilder
{
    public function __construct(
        protected SourceRegistry $sources,
        protected PreferenceResolver $preferences,
    ) {}

    /** @return array{start: Carbon, end: Carbon} */
    public function window(string $frequency, ?Carbon $now = null): array
    {
        $now = $now?->copy() ?? Carbon::now();

        return match ($frequency) {
            'daily' => ['start' => $now->copy()->subDay(), 'end' => $now],
            'weekly' => ['start' => $now->copy()->subWeek(), 'end' => $now],
            default => ['start' => $now->copy()->subWeek(), 'end' => $now],
        };
    }

    /**
     * Everything that belongs in this recipient's digest for this window:
     * persisted notifications plus whatever registered sources contribute.
     *
     * @return array{items: Collection, extras: array<string, mixed>}
     */
    public function collect(Identity $recipient, string $frequency, ?Carbon $now = null): array
    {
        $window = $this->window($frequency, $now);

        $candidates = NotificationItem::query()
            ->forRecipient($recipient)
            ->pendingDigest()
            ->where('created_at', '>=', $window['start'])
            ->where('created_at', '<=', $window['end'])
            ->orderBy('created_at')
            ->get();

        // Only types this recipient actually wants digested belong in the mail.
        // Without this split, anything that already went out as an immediate
        // e-mail would be repeated in the summary a few days later.
        [$items, $skipped] = $candidates->partition(
            fn (NotificationItem $item) => $this->preferences->allows($recipient, $item->type, 'digest')
        );

        return [
            'items' => $items->values(),
            'skipped' => $skipped->values(),
            'extras' => $this->sources->collect($recipient, $window['start'], $window['end']),
            'window' => $window,
        ];
    }

    /**
     * Records the send and stamps the items. Returns null when this window was
     * already sent to this recipient — the idempotency guarantee.
     */
    public function markSent(Identity $recipient, string $frequency, array $collected): ?NotificationDigestRun
    {
        $window = $collected['window'];

        $run = NotificationDigestRun::query()
            ->where('brand_id', BrandContext::hasCurrent() ? BrandContext::currentId() : BrandContext::defaultId())
            ->where('user_id', $recipient->userId)
            ->where('contact_uuid', $recipient->contactUuid)
            ->where('frequency', $frequency)
            ->where('window_start', $window['start'])
            ->first();

        if ($run !== null) {
            return null;
        }

        $run = NotificationDigestRun::create([
            'user_id' => $recipient->userId,
            'contact_uuid' => $recipient->contactUuid,
            'email' => $recipient->email,
            'frequency' => $frequency,
            'window_start' => $window['start'],
            'window_end' => $window['end'],
            'item_count' => $collected['items']->count(),
            'sent_at' => now(),
        ]);

        // Stamp the ones that were skipped too: they were considered for this
        // window and rejected. Leaving them pending would mean a later
        // preference change silently resurfaces weeks-old items.
        $stamp = $collected['items']->pluck('id')
            ->merge(($collected['skipped'] ?? collect())->pluck('id'));

        if ($stamp->isNotEmpty()) {
            NotificationItem::query()->whereIn('id', $stamp)->update(['digested_at' => now()]);
        }

        return $run;
    }

    /** Whether there is anything worth sending at all. */
    public function isEmpty(array $collected): bool
    {
        return $collected['items']->isEmpty() && $collected['extras'] === [];
    }
}
