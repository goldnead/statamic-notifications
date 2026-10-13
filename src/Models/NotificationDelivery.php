<?php

namespace Goldnead\Notifications\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $notification_item_id
 * @property string $channel
 * @property string $status pending|retry|accepted|skipped|cancelled|failed
 * @property int $attempts failed attempts; a first-try acceptance keeps 0
 * @property Carbon|null $available_at when a worker may claim this row again
 * @property Carbon|null $accepted_at set when the channel reported acceptance
 * @property string|null $outcome_code fixed vocabulary, never free text
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class NotificationDelivery extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RETRY = 'retry';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_FAILED = 'failed';

    /**
     * The only states a worker may claim. Everything else is terminal, and
     * terminal rows are never reset — not by a retry, not by the same fact
     * arriving again.
     *
     * @var list<string>
     */
    public const CLAIMABLE_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_RETRY,
    ];

    protected $table = 'notification_deliveries';

    protected $guarded = [];

    protected $casts = [
        'attempts' => 'integer',
        'available_at' => 'datetime',
        'accepted_at' => 'datetime',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(NotificationItem::class, 'notification_item_id');
    }

    /**
     * Rows a worker may pick up right now: claimable status, and due. A row
     * still in its backoff window is nobody's to send.
     */
    public function scopeClaimable(Builder $query, ?Carbon $now = null): Builder
    {
        return $query
            ->whereIn('status', self::CLAIMABLE_STATUSES)
            ->where(function (Builder $query) use ($now): void {
                $query->whereNull('available_at')
                    ->orWhere('available_at', '<=', $now ?? now());
            });
    }

    /**
     * The in-memory twin of {@see scopeClaimable()}, checked after the row is
     * locked: the claim list was assembled without a lock, so what a worker
     * holds may have moved on since the list was read.
     */
    public function isClaimable(): bool
    {
        return in_array($this->status, self::CLAIMABLE_STATUSES, true)
            && ($this->available_at === null || $this->available_at->lte(now()));
    }

    public function isTerminal(): bool
    {
        return ! in_array($this->status, self::CLAIMABLE_STATUSES, true);
    }
}
