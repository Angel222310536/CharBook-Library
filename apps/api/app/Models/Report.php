<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Report extends Model
{
    public const STATUS_OPEN = 'open';
    public const STATUS_IN_REVIEW = 'in_review';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_DISMISSED = 'dismissed';

    public const TARGET_TYPES = [
        'book', 'review', 'comment', 'user', 'salon_post', 'work', 'chapter',
    ];

    public const REASONS = [
        'spam', 'abuse', 'copyright', 'illegal',
        'inappropriate', 'misleading', 'other',
    ];

    public const RESOLUTION_ACTIONS = [
        'none', 'warn', 'hide', 'delete',
        'suspend_temp', 'suspend_perm', 'ban',
    ];

    protected $fillable = [
        'reporter_id', 'target_type', 'target_id',
        'reason', 'details', 'status',
        'resolved_by_id', 'resolution_notes', 'resolution_action',
        'resolved_at',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_id');
    }

    /**
     * Resuelve el target polimórfico al modelo concreto.
     * (No usamos MorphTo nativo porque guardamos strings como 'book' no FQCN)
     */
    public function target()
    {
        return match ($this->target_type) {
            'book'       => Book::find($this->target_id),
            'review'     => Review::find($this->target_id),
            'user'       => User::find($this->target_id),
            'comment',
            'salon_post' => null, // implementar cuando existan los modelos
            default      => null,
        };
    }

    public function scopeOpen($q)
    {
        return $q->where('status', self::STATUS_OPEN);
    }

    public function scopeFor($q, string $type, int $id)
    {
        return $q->where('target_type', $type)->where('target_id', $id);
    }
}
