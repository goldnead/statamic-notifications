<?php

namespace Goldnead\Notifications\Models;

use Goldnead\BrandContext\Concerns\HasBrand;
use Goldnead\IdentityContracts\Identity;
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
