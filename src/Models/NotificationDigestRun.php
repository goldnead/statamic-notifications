<?php

namespace Goldnead\Notifications\Models;

use Goldnead\BrandContext\Concerns\HasBrand;
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
}
