<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Message extends Model
{
    use SoftDeletes;

    public const CATEGORIES = ['review', 'report', 'achievement', 'event', 'tip', 'platform'];
    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    protected $fillable = [
        'user_id', 'category', 'priority', 'subject', 'body',
        'sender_name', 'sender_role', 'attachments', 'actions',
        'read_at', 'important_at', 'archived_at',
    ];

    protected $casts = [
        'attachments' => 'array',
        'actions' => 'array',
        'read_at' => 'datetime',
        'important_at' => 'datetime',
        'archived_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
