<?php

namespace Goldnead\Notifications\Models;

use Goldnead\BrandContext\Concerns\HasBrand;
use Goldnead\Notifications\Support\UniquenessKey;
use Illuminate\Database\Eloquent\Model;

class NotificationDigestRun extends Model
{
    use HasBrand;

    protected $table = 'notification_digest_runs';

    protected $guarded = [];

    protected $casts = [
        'window_start' => 'datetime',
        'window_end' => 'datetime',
        'sent_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $run) {
            $run->uniqueness_key = static::uniquenessKeyFor(
                $run->user_id,
                $run->contact_uuid,
                (string) $run->frequency,
                $run->window_start,
            );
        });
    }

    /**
     * The window is hashed in the same string form the column is stored in, so
     * a Carbon instance, a raw string and the value read back from the database
     * all produce one key rather than three.
     */
    public static function uniquenessKeyFor(?string $userId, ?string $contactUuid, string $frequency, mixed $windowStart): string
    {
        return UniquenessKey::of([
            $userId,
            $contactUuid,
            $frequency,
            $windowStart === null ? null : (new static)->fromDateTime($windowStart),
        ]);
    }
}
