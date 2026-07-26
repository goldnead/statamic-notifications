<?php

namespace Goldnead\Notifications\Models;

use Goldnead\BrandContext\Concerns\HasBrand;
use Goldnead\IdentityContracts\Identity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $brand_id
 * @property string $type
 * @property Carbon|null $read_at
 * @property Carbon|null $digested_at
 */
class NotificationItem extends Model
{
    use HasBrand;

    protected $table = 'notification_items';

    protected $guarded = [];

    protected $casts = [
        'data' => 'array',
        'read_at' => 'datetime',
        'digested_at' => 'datetime',
    ];

    public function recipient(): Identity
    {
        return new Identity(
            type: $this->recipient_type ?? Identity::TYPE_USER,
            id: $this->recipient_id,
            userId: $this->user_id,
            contactUuid: $this->contact_uuid,
            email: $this->email,
        );
    }

    public function actor(): ?Identity
    {
        if ($this->actor_type === null) {
            return null;
        }

        return new Identity(
            type: $this->actor_type,
            id: $this->actor_id,
            name: $this->actor_name,
        );
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    public function scopeOfType(Builder $query, string|array $type): Builder
    {
        return $query->whereIn('type', (array) $type);
    }

    /**
     * Everything addressed to a given person, matched on whichever join key the
     * notification was written with. An identity carrying no join key at all
     * must never match the whole table.
     */
    public function scopeForRecipient(Builder $query, Identity $identity): Builder
    {
        return $query->where(function (Builder $query) use ($identity): void {
            if ($identity->userId !== null) {
                $query->orWhere('user_id', $identity->userId);
            }

            if ($identity->contactUuid !== null) {
                $query->orWhere('contact_uuid', $identity->contactUuid);
            }

            if (! $identity->isIdentified()) {
                $query->whereRaw('1 = 0');
            }
        });
    }

    /** Not yet collected into any digest. */
    public function scopePendingDigest(Builder $query): Builder
    {
        return $query->whereNull('digested_at');
    }
}
