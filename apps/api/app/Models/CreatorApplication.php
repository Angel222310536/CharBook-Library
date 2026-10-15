<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreatorApplication extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_CHANGES_REQUESTED = 'changes_requested';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    public const ACTIVE_STATUSES = [self::STATUS_PENDING, self::STATUS_CHANGES_REQUESTED];

    protected $fillable = [
        'user_id', 'status',
        'display_name', 'pen_name', 'bio_long', 'sample_url',
        'genres', 'languages', 'why_join', 'social_links',
        'publish_frequency', 'publish_plan',
        'reviewer_id',
        'score_quality', 'score_commitment', 'score_professionalism', 'score_fit',
        'score_total', 'reviewer_notes',
        'submitted_at', 'reviewed_at',
    ];

    protected $casts = [
        'genres' => 'array',
        'languages' => 'array',
        'social_links' => 'array',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'score_quality' => 'integer',
        'score_commitment' => 'integer',
        'score_professionalism' => 'integer',
        'score_fit' => 'integer',
        'score_total' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    /** Calcula y persiste el score total a partir de los 4 ejes. */
    public function calculateScore(): int
    {
        $total = (int) $this->score_quality
            + (int) $this->score_commitment
            + (int) $this->score_professionalism
            + (int) $this->score_fit;
        $this->score_total = $total;
        return $total;
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }
}
