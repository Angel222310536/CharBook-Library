<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AffiliateClick extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'affiliate_link_id',
        'user_id',
        'ip_hash',
        'referrer',
        'user_agent',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function link(): BelongsTo
    {
        return $this->belongsTo(AffiliateLink::class, 'affiliate_link_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
