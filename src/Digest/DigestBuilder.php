<?php

namespace Goldnead\Notifications\Digest;

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\IdentityContracts\Identity;
use Goldnead\Notifications\Models\NotificationDigestRun;
use Goldnead\Notifications\Models\NotificationItem;
use Goldnead\Notifications\Preferences\PreferenceResolver;
use Illuminate\Database\Eloquent\Builder;
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
     * @return array{items: Collection, extras: array<string, array{line: string}>}
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

        // Sources are asked "what is new since this person last heard from us",
        // not "what does the window cover". The difference is the whole bug:
        // an item carries its own `digested_at`, a source's subject matter does
        // not. An open follow-up is still open next week, so a source that gets
        // the plain window start reports it again, and again — the digest is
        // never empty and goes out every week with nothing in it.
        $since = $this->lastReported($recipient, $frequency) ?? $window['start'];

        return [
            'items' => $items->values(),
            'skipped' => $skipped->values(),
            'extras' => $this->sources->collect($recipient, $since, $window['end']),
            'window' => $window,
        ];
    }

    /**
     * Run rows of the brand this send belongs to.
     *
     * Both readers below go through here so the brand filter cannot drift
     * apart between them. Written out by hand rather than left to the global
     * scope because a console run has no current brand, and a scope that fails
     * closed would answer "never sent" for everybody.
     *
     * @return Builder<NotificationDigestRun>
     */
    protected function runs(): Builder
    {
        return NotificationDigestRun::query()->where(
            'brand_id',
            BrandContext::hasCurrent() ? BrandContext::currentId() : BrandContext::defaultId(),
        );
    }

    /**
     * End of the last window this recipient was actually sent, or null when
     * they have never been sent one.
     *
     * Read from the run rows rather than from a new column: the send record
     * already exists and is already the thing that says "they have been told".
     * A contact recipient is compared by value, so a NULL user_id is matched
     * rather than compared against a NULL that equals nothing.
     *
     * Known limit, older than this method and inherited with open eyes: the run
     * row is written before the mail leaves, so a run that stamped and then
     * failed to deliver still counts as "reported". Items have carried that
     * same risk for as long as `digested_at` has existed — the send command
     * names it out loud and ends non-zero — and sources now share it. Whatever
     * became overdue inside such a window is passed over on the next run.
     */
    protected function lastReported(Identity $recipient, string $frequency): ?Carbon
    {
        $query = $this->runs()->where('frequency', $frequency);

        if ($recipient->userId === null) {
            $query->whereNull('user_id');
        } else {
            $query->where('user_id', $recipient->userId);
        }

        if ($recipient->contactUuid === null) {
            $query->whereNull('contact_uuid');
        } else {
            $query->where('contact_uuid', $recipient->contactUuid);
        }

        $end = $query->max('window_end');

        return $end === null ? null : Carbon::parse($end);
    }

    /**
     * Records the send and stamps the items. Returns null when this window was
     * already sent to this recipient — the idempotency guarantee.
     */
    public function markSent(Identity $recipient, string $frequency, array $collected): ?NotificationDigestRun
    {
        $window = $collected['window'];

        // Matched on the same key the unique index is built on, so the check
        // and the constraint can never disagree — and so a contact recipient,
        // whose user_id is NULL, is compared by value rather than by a NULL
        // that equals nothing.
        $run = $this->runs()
            ->where('uniqueness_key', NotificationDigestRun::uniquenessKeyFor(
                $recipient->userId,
                $recipient->contactUuid,
                $frequency,
                $window['start'],
            ))
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
