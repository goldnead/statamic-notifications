<?php

namespace Goldnead\Notifications\Models;

use Goldnead\BrandContext\Concerns\HasBrand;
use Goldnead\IdentityContracts\Identity;
use Goldnead\Notifications\Support\UniquenessKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class NotificationPreference extends Model
{
    use HasBrand;

    protected $table = 'notification_preferences';

    protected $guarded = [];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    protected static function booted(): void
    {
        // Recomputed on every save, never trusted from the caller: the column
        // is what the unique index is built on, and a stale value there is a
        // duplicate the database will happily accept.
        static::saving(function (self $preference) {
            $preference->uniqueness_key = static::uniquenessKeyFor(
                $preference->user_id,
                $preference->contact_uuid,
                (string) $preference->type,
                (string) $preference->channel,
            );
        });
    }

    public static function uniquenessKeyFor(?string $userId, ?string $contactUuid, string $type, string $channel): string
    {
        return UniquenessKey::of([$userId, $contactUuid, $type, $channel]);
    }

    /**
     * Preferences are matched on the exact join keys they were stored with —
     * an OR match would let a contact preference silently govern a user.
     */
    public function scopeForRecipient(Builder $query, Identity $identity): Builder
    {
        return $query
            ->where('user_id', $identity->userId)
            ->where('contact_uuid', $identity->contactUuid);
    }
}
