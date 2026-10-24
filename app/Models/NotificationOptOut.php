<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A "do not send" entry: channel ('*' = every channel) × category
 * ('marketing' = offers and greetings only, 'all' = transactional too).
 */
class NotificationOptOut extends Model
{
    protected $fillable = ['master_profile_id', 'lead_id', 'channel', 'category', 'note', 'created_by'];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(MasterProfile::class, 'master_profile_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(CrmLead::class, 'lead_id');
    }
}
